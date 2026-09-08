<?php

namespace App\Notifications;

use App\Models\Actionlog;
use App\Models\ActionlogAttachment;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\Location;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\Channels\WeComWebhookChannel;
use App\Services\CustomFeatureService;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetActivityNotification extends Notification
{
    private const WECOM_IMAGE_MAX_BYTES = 2097152;

    private Actionlog $actionlog;
    private Asset $asset;
    private ?User $admin;

    public function __construct(Actionlog $actionlog, Asset $asset, ?User $admin = null)
    {
        $this->actionlog = $actionlog;
        $this->asset = $asset;
        $this->admin = $admin;
    }

    public function via(): array
    {
        $settings = Setting::getSettings();

        if ($settings?->webhook_selected === 'wecom' && $settings->webhook_endpoint) {
            return [WeComWebhookChannel::class];
        }

        return [];
    }

    public function toSlack(): SlackMessage
    {
        $this->actionlog->loadMissing('mentionedAssets', 'attachments');

        $title = $this->actionTitle();
        $assetName = htmlspecialchars_decode($this->asset->present()->name);
        $fields = [
            '资产标签' => $this->assetLink($this->asset),
            '设备名称' => $this->asset->name ?: $assetName,
            '设备型号' => $this->asset->model?->name ?: '—',
            '操作人' => $this->admin?->present()->fullName() ?: '系统',
            '操作时间' => (string) $this->actionlog->action_date,
        ];

        if ($target = $this->targetName()) {
            $fields['操作对象'] = $target;
        }

        $details = array_filter([
            $this->formattedAttachments(),
            $this->formattedNote(),
            $this->formattedChanges(),
        ]);

        return (new SlackMessage)
            ->success()
            ->content('🔄 '.$title)
            ->from('Snipe-IT')
            ->to('#NA')
            ->attachment(function ($attachment) use ($assetName, $fields, $details) {
                $attachment
                    ->title($assetName, $this->asset->present()->viewUrl())
                    ->fields($fields)
                    ->content(implode("\n", $details));
            });
    }

    public function toWeComImages(): array
    {
        if (! $this->featureEnabled('actionlog_attachments') || ! $this->featureEnabled('wecom_attachment_images')) {
            return [];
        }

        $this->actionlog->loadMissing('attachments');

        return $this->actionlog->attachments
            ->filter(fn (ActionlogAttachment $attachment) => $this->isWeComImage($attachment))
            ->take(3)
            ->map(fn (ActionlogAttachment $attachment) => [
                'path' => Storage::path($attachment->path),
                'filename' => $attachment->original_filename ?: $attachment->filename,
                'caption' => $this->imageCaption($attachment),
            ])
            ->values()
            ->all();
    }

    private function actionTitle(): string
    {
        return match ($this->actionlog->action_type) {
            'audit' => '设备盘点已完成',
            'checkin from' => '设备已归还',
            'checkout' => '设备已领用',
            'create' => '设备已创建',
            'delete' => '设备已删除',
            'restore' => '设备已恢复',
            'pending return' => '设备已标记待归还',
            'pending return update' => '设备待归还记录已更新',
            'requested' => '设备申请已提交',
            'request canceled', 'request_canceled' => '设备申请已取消',
            'retire' => '设备已退役',
            'transfer' => '设备已调拨',
            'accepted' => '设备领用已接受',
            'declined' => '设备领用已拒绝',
            'uploaded' => '设备附件已上传',
            'note added' => '设备备注已添加',
            'update' => '设备资料已更新',
            default => '设备操作：'.$this->actionlog->action_type,
        };
    }

    private function actionName(): string
    {
        return match ($this->actionlog->action_type) {
            'audit' => '盘点',
            'checkin from' => '归还',
            'checkout' => '领用',
            'create' => '创建',
            'delete' => '删除',
            'restore' => '恢复',
            'pending return' => '待归还',
            'pending return update' => '待归还更新',
            'requested' => '申请',
            'request canceled', 'request_canceled' => '取消申请',
            'retire' => '退租',
            'transfer' => '移交',
            'accepted' => '领用接受',
            'declined' => '领用拒绝',
            'uploaded' => '附件上传',
            'note added' => '备注',
            'update' => '资料更新',
            default => $this->actionlog->action_type,
        };
    }

    private function imageCaption(ActionlogAttachment $attachment): string
    {
        $filename = $attachment->original_filename ?: $attachment->filename;

        return '📎 截图说明：'.$this->assetLink($this->asset)
            .' 本次'.$this->actionName().'操作，文件：'.$filename;
    }

    private function targetName(): ?string
    {
        $target = $this->actionlog->target;

        if (! $target) {
            return null;
        }

        if ($target instanceof User) {
            return $target->present()->fullName();
        }

        if ($target instanceof Asset) {
            return $this->assetLink($target).' - '.($target->name ?: $target->present()->name);
        }

        return $target->name ?? null;
    }

    private function formattedNote(): string
    {
        $note = trim((string) $this->actionlog->note);

        if (! $this->featureEnabled('operation_asset_mentions')) {
            return $note;
        }

        $originalNote = $note;
        $linkedAssetIds = [];
        $linkedAssetTags = [];

        foreach ($this->actionlog->mentionedAssets as $asset) {
            $mention = '@'.$asset->asset_tag;

            if (str_contains($note, $mention)) {
                $note = str_replace($mention, $this->assetLink($asset, $mention), $note);
                $linkedAssetIds[] = $asset->id;
                $linkedAssetTags[] = $asset->asset_tag;
            }
        }

        foreach ($this->assetsMentionedInText($originalNote, $linkedAssetTags) as $asset) {
            $mention = '@'.$asset->asset_tag;
            $note = str_replace($mention, $this->assetLink($asset, $mention), $note);
        }

        $missingLinks = $this->actionlog->mentionedAssets
            ->reject(fn (Asset $asset) => in_array($asset->id, $linkedAssetIds, true))
            ->map(fn (Asset $asset) => $this->assetLink($asset))
            ->values()
            ->all();

        if ($missingLinks !== []) {
            $note .= ($note !== '' ? "\n" : '').'关联设备：'.implode(' ', $missingLinks);
        }

        return $note;
    }

    private function assetsMentionedInText(string $note, array $ignoredAssetTags)
    {
        if ($note === '' || ! preg_match_all('/@([A-Za-z0-9][A-Za-z0-9._-]*)/u', $note, $matches)) {
            return collect();
        }

        $assetTags = collect($matches[1])
            ->unique()
            ->diff($ignoredAssetTags)
            ->values();

        if ($assetTags->isEmpty()) {
            return collect();
        }

        return Asset::whereIn('asset_tag', $assetTags)->get();
    }

    private function assetLink(Asset $asset, ?string $label = null): string
    {
        $label = $label ?: $asset->asset_tag;

        return '['.$label.']('.$asset->present()->viewUrl().')';
    }

    private function formattedAttachments(): string
    {
        if (! $this->featureEnabled('actionlog_attachments')) {
            return '';
        }

        $this->actionlog->loadMissing('attachments');

        if ($this->actionlog->attachments->isEmpty()) {
            return '';
        }

        $links = $this->actionlog->attachments
            ->map(function (ActionlogAttachment $attachment) {
                $url = route('hardware.actionlog-attachments.show', [
                    'asset' => $attachment->asset_id,
                    'attachment' => $attachment->id,
                ]);
                $filename = $attachment->original_filename ?: $attachment->filename;

                return '['.$filename.']('.$url.')';
            })
            ->values()
            ->all();

        $summary = '📷 **操作截图**：'.implode(' ', $links);

        if ($this->toWeComImages() !== []) {
            $summary .= "\n📌 图片预览：已随下方图片发送";
        }

        return $summary;
    }

    private function isWeComImage(ActionlogAttachment $attachment): bool
    {
        $path = Storage::path($attachment->path);

        if (! is_file($path)) {
            return false;
        }

        $filesize = filesize($path);

        if ($filesize === false || $filesize <= 0 || $filesize > self::WECOM_IMAGE_MAX_BYTES) {
            return false;
        }

        $extension = strtolower(pathinfo($attachment->filename, PATHINFO_EXTENSION));
        $mimeType = strtolower((string) $attachment->mime_type);

        return in_array($extension, ['jpg', 'jpeg', 'png'], true)
            || in_array($mimeType, ['image/jpeg', 'image/png'], true);
    }

    private function formattedChanges(): string
    {
        $changes = json_decode((string) $this->actionlog->log_meta, true);

        if (! is_array($changes)) {
            return '';
        }

        $lines = [];

        foreach ($changes as $field => $values) {
            if (! is_array($values) || in_array($field, ['created_at', 'updated_at'], true)) {
                continue;
            }

            $old = $this->formatValue($field, $values['old'] ?? null);
            $new = $this->formatValue($field, $values['new'] ?? null);
            $lines[] = '**'.$this->fieldLabel($field)."**：{$old} → {$new}";
        }

        return implode("\n", $lines);
    }

    private function fieldLabel(string $field): string
    {
        return [
            'name' => '设备名称',
            'asset_tag' => '资产标签',
            'serial' => '序列号',
            'model_id' => '设备型号',
            'status_id' => '状态',
            'location_id' => '位置',
            'rtd_location_id' => '默认位置',
            'company_id' => '公司',
            'supplier_id' => '供应商',
            'purchase_date' => '采购日期',
            'purchase_cost' => '采购成本',
            'expected_checkin' => '预计归还日期',
            'next_audit_date' => '下次盘点日期',
            'notes' => '备注',
            'requestable' => '允许申请',
            'byod' => '自带设备',
        ][$field] ?? $field;
    }

    private function formatValue(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '空';
        }

        $resolved = match ($field) {
            'model_id' => AssetModel::find($value)?->name,
            'status_id' => Statuslabel::find($value)?->name,
            'location_id', 'rtd_location_id' => Location::find($value)?->name,
            'company_id' => Company::find($value)?->name,
            'supplier_id' => Supplier::find($value)?->name,
            'requestable', 'byod' => (bool) $value ? '是' : '否',
            default => null,
        };

        if ($resolved !== null) {
            return (string) $resolved;
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return Str::limit((string) $value, 160);
    }

    private function featureEnabled(string $key): bool
    {
        return app(CustomFeatureService::class)->enabled($key);
    }
}
