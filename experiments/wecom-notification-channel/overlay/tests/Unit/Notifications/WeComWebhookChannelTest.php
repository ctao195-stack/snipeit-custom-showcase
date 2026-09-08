<?php

namespace Tests\Unit\Notifications;

use App\Notifications\Channels\WeComWebhookChannel;
use GuzzleHttp\Client;
use Illuminate\Notifications\Messages\SlackMessage;
use PHPUnit\Framework\TestCase;

class WeComWebhookChannelTest extends TestCase
{
    public function test_it_converts_existing_slack_notifications_to_wecom_markdown(): void
    {
        $message = (new SlackMessage)
            ->content(':arrow_up: :computer: Asset checked out')
            ->attachment(function ($attachment) {
                $attachment
                    ->title('MacBook Pro', 'https://snipe-it.example.com/hardware/1')
                    ->fields([
                        'To' => '<https://snipe-it.example.com/users/1|Test User>',
                        'By' => 'Administrator',
                    ])
                    ->content('Test note');
            });

        $markdown = (new WeComWebhookChannel(new Client))->toMarkdown($message);

        $this->assertStringContainsString('⬆️ 💻 Asset checked out', $markdown);
        $this->assertStringContainsString('[MacBook Pro](https://snipe-it.example.com/hardware/1)', $markdown);
        $this->assertStringContainsString('[Test User](https://snipe-it.example.com/users/1)', $markdown);
        $this->assertStringContainsString('Test note', $markdown);
    }

    public function test_it_keeps_wecom_markdown_within_the_byte_limit(): void
    {
        $message = (new SlackMessage)->content(str_repeat('资产通知', 2000));

        $markdown = (new WeComWebhookChannel(new Client))->toMarkdown($message);

        $this->assertLessThanOrEqual(4000, strlen($markdown));
        $this->assertStringEndsWith('...', $markdown);
    }
}
