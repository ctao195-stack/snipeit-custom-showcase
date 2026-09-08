<?php

namespace App\Notifications\Channels;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Notifications\Messages\SlackAttachmentField;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use App\Services\CustomFeatureService;
use RuntimeException;

class WeComWebhookChannel
{
    private const MAX_CONTENT_BYTES = 4000;

    protected HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * Send an existing Snipe-IT Slack notification through a WeCom group robot.
     *
     * Reusing toSlack() keeps all existing checkout, checkin, request, and audit
     * notification details in one place while translating the payload to the
     * message format expected by WeCom.
     */
    public function send($notifiable, Notification $notification)
    {
        if (! $url = $notifiable->routeNotificationFor('wecom', $notification)) {
            return null;
        }

        if (! method_exists($notification, 'toSlack')) {
            throw new RuntimeException('The notification cannot be formatted for WeCom.');
        }

        $message = $notification->toSlack($notifiable);

        if (! $message instanceof SlackMessage) {
            throw new RuntimeException('The notification returned an unsupported WeCom message.');
        }

        $response = $this->http->post($url, [
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'msgtype' => 'markdown',
                'markdown' => [
                    'content' => $this->toMarkdown($message),
                ],
            ],
        ]);

        $result = json_decode((string) $response->getBody(), true);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("WeCom webhook returned HTTP {$status}.");
        }

        if (! is_array($result) || (int) ($result['errcode'] ?? -1) !== 0) {
            $error = is_array($result) ? ($result['errmsg'] ?? 'unknown error') : 'invalid response';
            throw new RuntimeException("WeCom webhook rejected the message: {$error}.");
        }

        $this->sendImageMessages($url, $notification);

        return $response;
    }

    private function sendImageMessages(string $url, Notification $notification): void
    {
        if (! method_exists($notification, 'toWeComImages')) {
            return;
        }

        foreach ($notification->toWeComImages() as $image) {
            $path = $image['path'] ?? null;

            if (! is_string($path) || ! is_file($path)) {
                continue;
            }

            try {
                $response = $this->http->post($url, [
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'msgtype' => 'image',
                        'image' => [
                            'base64' => base64_encode(file_get_contents($path)),
                            'md5' => md5_file($path),
                        ],
                    ],
                ]);

                $result = json_decode((string) $response->getBody(), true);
                $status = $response->getStatusCode();

                if ($status < 200 || $status >= 300 || ! is_array($result) || (int) ($result['errcode'] ?? -1) !== 0) {
                    Log::warning('WeCom attachment image notification failed.', [
                        'filename' => $image['filename'] ?? basename($path),
                        'status' => $status,
                        'response' => $result,
                    ]);

                    continue;
                }

                if (app(CustomFeatureService::class)->enabled('wecom_image_caption')) {
                    $this->sendImageCaption($url, $image['caption'] ?? null, $image['filename'] ?? basename($path));
                }
            } catch (\Throwable $exception) {
                Log::warning('WeCom attachment image notification failed.', [
                    'filename' => $image['filename'] ?? basename($path),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function sendImageCaption(string $url, ?string $caption, string $filename): void
    {
        $caption = trim((string) $caption);

        if ($caption === '') {
            return;
        }

        try {
            $response = $this->http->post($url, [
                'allow_redirects' => false,
                'http_errors' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'msgtype' => 'markdown',
                    'markdown' => [
                        'content' => $this->truncateToByteLimit($caption),
                    ],
                ],
            ]);

            $result = json_decode((string) $response->getBody(), true);
            $status = $response->getStatusCode();

            if ($status < 200 || $status >= 300 || ! is_array($result) || (int) ($result['errcode'] ?? -1) !== 0) {
                Log::warning('WeCom attachment image caption notification failed.', [
                    'filename' => $filename,
                    'status' => $status,
                    'response' => $result,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('WeCom attachment image caption notification failed.', [
                'filename' => $filename,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function toMarkdown(SlackMessage $message): string
    {
        $lines = [];
        $content = $this->normalizeText($message->content ?? '');

        if ($content !== '') {
            $lines[] = $content;
        }

        foreach ($message->attachments as $attachment) {
            $title = $this->normalizeText($attachment->title ?? '');
            $url = trim((string) ($attachment->url ?? ''));

            if ($title !== '') {
                $lines[] = $url !== '' ? "[{$title}]({$url})" : "**{$title}**";
            }

            foreach (($attachment->fields ?? []) as $key => $value) {
                if ($value instanceof SlackAttachmentField) {
                    $field = $value->toArray();
                    $key = $field['title'] ?? '';
                    $value = $field['value'] ?? '';
                }

                $fieldName = $this->normalizeText((string) $key);
                $fieldValue = $this->normalizeText((string) $value);

                if ($fieldName !== '' || $fieldValue !== '') {
                    $lines[] = "> **{$fieldName}**：{$fieldValue}";
                }
            }

            $attachmentContent = $this->normalizeText($attachment->content ?? '');

            if ($attachmentContent !== '') {
                $lines[] = "> {$attachmentContent}";
            }
        }

        return $this->truncateToByteLimit(trim(implode("\n", $lines)));
    }

    private function normalizeText(string $value): string
    {
        $value = preg_replace_callback(
            '/<((?:https?:\\/\\/)[^>|]+)\\|([^>]+)>/',
            fn (array $match) => "[{$match[2]}]({$match[1]})",
            $value
        );

        $value = preg_replace('/<((?:https?:\\/\\/)[^>]+)>/', '$1', $value);
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $value = strtr($value, [
            ':arrow_up:' => '⬆️',
            ':arrow_down:' => '⬇️',
            ':computer:' => '💻',
            ':keyboard:' => '⌨️',
            ':card_file_box:' => '🗃️',
            ':heart:' => '❤️',
        ]);

        return trim(str_replace("\r", '', $value));
    }

    private function truncateToByteLimit(string $content): string
    {
        if (strlen($content) <= self::MAX_CONTENT_BYTES) {
            return $content;
        }

        $limit = self::MAX_CONTENT_BYTES - 3;
        $truncated = function_exists('mb_strcut')
            ? mb_strcut($content, 0, $limit, 'UTF-8')
            : substr($content, 0, $limit);

        return rtrim($truncated)."...";
    }
}
