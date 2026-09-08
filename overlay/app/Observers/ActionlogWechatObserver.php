<?php

namespace App\Observers;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Company;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ActionlogWechatObserver
{
    private const WECOM_IMAGE_LIMIT = 3;
    private const WECOM_IMAGE_MAX_BYTES = 2097152;
    private const WECOM_IMAGE_NATIVE_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    private const WECOM_IMAGE_CONVERTIBLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public function created(Actionlog $log): void
    {
        $logId = $log->id;

        app()->terminating(function () use ($logId) {
            $freshLog = Actionlog::find($logId);

            if ($freshLog) {
                $this->send($freshLog);
            }
        });
    }

    private function send(Actionlog $log): void
    {
        $webhook = $this->wecomWebhookUrl();

        if ($webhook === '') {
            return;
        }

        try {
            $log->loadMissing([
                'adminuser',
                'user.department',
                'target',
                'item',
                'attachments',
            ]);

            if ((string) $log->item_type === 'App\\Models\\Asset') {
                $log->loadMissing([
                    'item.model',
                    'item.model.category',
                    'item.location',
                    'item.assetstatus',
                    'item.assignedTo',
                ]);
            } elseif ((string) $log->item_type === User::class) {
                $log->loadMissing([
                    'item.department',
                ]);
            }

            [$title, $kind] = $this->resolveAction($log);
            $content = $kind === 'user'
                ? $this->buildUserContent($log, $title)
                : $this->buildAssetContent($log, $title);

            Http::asJson()
                ->timeout(5)
                ->post($webhook, [
                    'msgtype' => 'markdown',
                    'markdown' => [
                        'content' => $content,
                    ],
                ])
                ->throw();

            $this->sendAttachmentImages($log, $webhook);
        } catch (\Throwable $e) {
            Log::warning('WeCom actionlog notify failed', [
                'action_log_id' => $log->id,
                'action_type' => $log->action_type,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveAction(Actionlog $log): array
    {
        $type = strtolower((string) $log->action_type);
        $targetType = (string) $log->target_type;
        $itemType = (string) $log->item_type;
        $note = mb_strtolower((string) ($log->note ?? ''));
        $newStatusName = $this->resolveNewStatusName($log);
        $currentStatusName = mb_strtolower((string) data_get($log->item, 'assetstatus.name', ''));

        if ($targetType === User::class || $itemType === User::class) {
            if (str_contains($type, 'create')) {
                return ['新增用户', 'user'];
            }

            if (str_contains($type, 'update')) {
                return ['更新用户', 'user'];
            }

            if (str_contains($type, 'delete')) {
                return ['删除用户', 'user'];
            }
        }

        if ($type === 'create' && $itemType === 'App\\Models\\Asset') {
            return ['入库', 'asset'];
        }

        if ($type === 'delete' && $itemType === 'App\\Models\\Asset') {
            return ['删除设备', 'asset'];
        }

        if (str_contains($type, 'pending return canceled') || str_contains($type, 'pending return cancelled') || $this->containsAny($note, ['待归还已取消', '取消待归还'])) {
            return ['待归还已取消', 'asset'];
        }

        if (str_contains($type, 'pending return completed') || $this->containsAny($note, ['待归还已完成'])) {
            return ['待归还已完成', 'asset'];
        }

        if (str_contains($type, 'pending return update') || $this->containsAny($note, ['更新待归还'])) {
            return ['更新待归还', 'asset'];
        }

        if (str_contains($type, 'pending return') || $this->containsAny($note, ['待归还'])) {
            return ['待归还', 'asset'];
        }

        if ($type === 'update' && $newStatusName !== '') {
            $statusTitle = $this->matchStatusTitle($newStatusName);
            if ($statusTitle !== null) {
                return [$statusTitle, 'asset'];
            }
        }

        if (str_contains($type, 'checkin')) {
            return ['归还', 'asset'];
        }

        if (str_contains($type, 'checkout') || str_contains($type, 'assign')) {
            if ($this->containsAny($note, ['借用', '临时借用'])) {
                return ['借用中', 'asset'];
            }

            $statusTitle = $this->matchStatusTitle($currentStatusName);
            if ($statusTitle !== null && $statusTitle !== '归还' && $statusTitle !== '入库') {
                return [$statusTitle, 'asset'];
            }

            return ['领用', 'asset'];
        }

        if ($this->containsAny($note, ['退租', '到期退租'])) {
            return ['退租', 'asset'];
        }

        if ($this->containsAny($note, ['维修', '返修', '返厂维修', '报修'])) {
            return ['维修中', 'asset'];
        }

        if ($this->containsAny($note, ['换新', '更换', '更换设备', '更换新设备', '已更换'])) {
            return ['已换新', 'asset'];
        }

        if ($this->containsAny($note, ['借用', '临时借用'])) {
            return ['借用中', 'asset'];
        }

        if (str_contains($type, 'checkout') || str_contains($type, 'assign')) {
            return ['领用', 'asset'];
        }

        return ['操作', 'asset'];
    }

    private function buildUserContent(Actionlog $log, string $title): string
    {
        $actorName = $this->displayName($log->adminuser);
        $targetUser = $log->target instanceof User ? $log->target : $log->user;
        $headline = $this->headlineFor($title, 'user');

        $lines = [
            $headline,
            "操作人： {$actorName}",
            '用户名称： '.$this->displayName($targetUser),
            '所属部门： '.data_get($targetUser, 'department.name', '-'),
            '时间： '.$this->formatTime($log),
        ];

        return implode("\n", $lines);
    }

    private function buildAssetContent(Actionlog $log, string $title): string
    {
        $actorName = $this->displayName($log->adminuser);
        $item = $log->item;
        $targetName = $this->displayName($log->target);
        $changeLines = $this->formatChangeLines($log);
        $headline = $this->headlineFor($title, 'asset');
        $isPendingReturn = in_array($title, ['待归还', '更新待归还', '待归还已完成', '待归还已取消'], true);
        $pendingReturnDetails = $isPendingReturn ? $this->pendingReturnDetails($log) : [];

        if ($isPendingReturn) {
            return $this->buildPendingReturnActivityContent($log, $title, $actorName);
        }

        if ($title === '操作' && $changeLines !== []) {
            $headline = '📢【资产变动：'.$this->changeSummaryTitle($log).'】';
        }

        $lines = [
            $headline,
            $this->assetLink($item, $this->assetTitle($item)),
            "操作人： {$actorName}",
        ];

        if ($title === '领用') {
            $lines[] = '领用人： '.data_get($item, 'assignedTo.name', $targetName);
        } elseif ($title === '借用中') {
            $lines[] = '借用人： '.data_get($item, 'assignedTo.name', $targetName);
        } elseif ($isPendingReturn) {
            $lines[] = '操作内容： '.($title === '更新待归还' ? '更新待归还信息' : '记录旧设备待归还');
            $lines[] = '当前使用人： '.($pendingReturnDetails['user'] ?? data_get($item, 'assignedTo.name', $targetName));
        } elseif (in_array($title, ['归还', '维修中', '退租'], true)) {
            $lines[] = '归还人： '.$targetName;
        } elseif ($title === '已换新') {
            $lines[] = '使用人： '.data_get($item, 'assignedTo.name', $targetName);
        } elseif ($title === '删除设备') {
            $lines[] = '删除对象： '.$this->assetLink($item, $this->assetTitle($item));
        }

        if ($log->target instanceof Asset && $title !== '删除设备') {
            $lines[] = '操作对象： '.$this->assetLink($log->target, $this->assetSummary($log->target));
        }

        if ($changeLines !== []) {
            $lines[] = '变更内容：';
            foreach ($changeLines as $changeLine) {
                $lines[] = $changeLine;
            }
        }

        $lines[] = '资产标签： '.$this->assetLink($item, (string) data_get($item, 'asset_tag', '-'));
        $lines[] = '序列号： '.data_get($item, 'serial', '-');
        $lines[] = '型号： '.data_get($item, 'model.name', '-');
        $lines[] = '位置： '.data_get($item, 'location.name', data_get($log, 'location.name', '-'));

        if ($isPendingReturn) {
            foreach ($this->formatPendingReturnLines($log, $pendingReturnDetails) as $pendingReturnLine) {
                $lines[] = $pendingReturnLine;
            }
        }

        $note = $this->resolveDisplayNote($log, $title);
        if (! $isPendingReturn && $note !== null) {
            $lines[] = '备注： '.$this->linkAssetMentions($note);
        }

        foreach ($this->formatAttachmentLines($log, $item) as $attachmentLine) {
            $lines[] = $attachmentLine;
        }

        $lines[] = '时间： '.$this->formatTime($log);

        return implode("\n", $lines);
    }

    private function wecomWebhookUrl(): string
    {
        return trim((string) (
            config('services.wecom.webhook')
            ?: config('services.wecom.webhook_url')
            ?: ''
        ));
    }

    private function buildPendingReturnActivityContent(Actionlog $log, string $title, string $actorName): string
    {
        $item = $log->item;
        $target = $log->target instanceof Asset ? $log->target : null;
        $note = $this->normalizeNote((string) ($log->note ?? ''));

        $lines = [
            '🔄 '.$this->pendingReturnActivityTitle($title),
            $this->assetLink($item, $this->assetTitle($item)),
            '> **资产标签**： '.$this->assetLink($item, (string) data_get($item, 'asset_tag', '-')),
            '> **设备名称**： '.data_get($item, 'name', '-'),
            '> **设备型号**： '.data_get($item, 'model.name', '-'),
            '> **操作人**： '.$actorName,
            '> **操作时间**： '.$this->formatTime($log),
        ];

        if ($target) {
            $lines[] = '> **操作对象**： '.$this->assetLink($target, (string) data_get($target, 'asset_tag', '-')).' - '.data_get($target, 'name', '-');
        } elseif ($log->target) {
            $lines[] = '> **操作对象**： '.$this->displayName($log->target);
        }

        if ($note !== '') {
            $lines[] = '> '.$this->linkAssetMentions($note);
        }

        foreach ($this->formatAttachmentLines($log, $item, true) as $attachmentLine) {
            $lines[] = $attachmentLine;
        }

        return implode("\n", $lines);
    }

    private function pendingReturnActivityTitle(string $title): string
    {
        return match ($title) {
            '更新待归还' => '设备待归还记录已更新',
            '待归还已完成' => '设备待归还已完成',
            '待归还已取消' => '设备待归还已取消',
            default => '设备已标记待归还',
        };
    }

    private function pendingReturnDetails(Actionlog $log): array
    {
        $note = $this->normalizeNote((string) ($log->note ?? ''));
        $details = [];

        if (preg_match('/(?:待归还|更新待归还)：.*?仍由\\s*(.*?)\\s*持有，原因：(.+?)。/u', $note, $matches)) {
            $details['user'] = trim($matches[1]);
            $details['reason'] = trim($matches[2]);
        }

        if (preg_match('/待归还已完成：.*?已由\\s*(.*?)\\s*归还。/u', $note, $matches)) {
            $details['user'] = trim($matches[1]);
        }

        if (preg_match('/原因：([^。]+)。/u', $note, $matches)) {
            $details['reason'] = trim($matches[1]);
        }

        if (preg_match('/关联新设备：@?([A-Za-z0-9._-]+)。/u', $note, $matches)) {
            $details['replacement_asset_tag'] = trim($matches[1]);
        }

        if (preg_match('/预计归还：([^。]+)。/u', $note, $matches)) {
            $details['expected_return_date'] = trim($matches[1]);
        }

        if (preg_match('/实际归还：([^。]+)。/u', $note, $matches)) {
            $details['actual_return_date'] = trim($matches[1]);
        }

        if (preg_match('/备注：(.+)$/us', $note, $matches)) {
            $details['note'] = trim($matches[1]);
        }

        return array_filter($details, fn ($value) => $value !== '');
    }

    private function formatPendingReturnLines(Actionlog $log, array $details): array
    {
        $lines = [];

        if (! empty($details['reason'])) {
            $lines[] = '待归还原因： '.$details['reason'];
        }

        if (! empty($details['expected_return_date'])) {
            $lines[] = '预计归还日期： '.$details['expected_return_date'];
        }

        if (! empty($details['actual_return_date'])) {
            $lines[] = '实际归还时间： '.$details['actual_return_date'];
        }

        $replacementAsset = $log->target_type === Asset::class ? $log->target : null;
        $replacementText = $replacementAsset
            ? $this->assetSummary($replacementAsset)
            : ($details['replacement_asset_tag'] ?? null);

        if ($replacementText) {
            $lines[] = '关联新设备： '.$replacementText;
        }

        if (! empty($details['note'])) {
            $lines[] = '备注信息： '.$details['note'];
        }

        return $lines;
    }

    private function formatChangeLines(Actionlog $log): array
    {
        if (strtolower((string) $log->action_type) !== 'update') {
            return [];
        }

        $meta = json_decode((string) ($log->log_meta ?? ''), true);
        if (!is_array($meta)) {
            return [];
        }

        $lines = [];
        foreach ($meta as $field => $change) {
            if (!is_array($change) || !array_key_exists('old', $change) || !array_key_exists('new', $change)) {
                continue;
            }

            $oldValue = $this->linkAssetMentions($this->formatChangeValue((string) $field, $change['old']));
            $newValue = $this->linkAssetMentions($this->formatChangeValue((string) $field, $change['new']));

            if ($oldValue === $newValue) {
                continue;
            }

            $lines[] = '- '.$this->changeFieldLabel((string) $field).'：'.$oldValue.' → '.$newValue;
        }

        return $lines;
    }

    private function changeSummaryTitle(Actionlog $log): string
    {
        $meta = json_decode((string) ($log->log_meta ?? ''), true);
        if (!is_array($meta)) {
            return '信息更新';
        }

        $labels = [];
        foreach (array_keys($meta) as $field) {
            $labels[] = $this->changeFieldLabel((string) $field);
        }

        $labels = array_values(array_unique(array_filter($labels)));
        if ($labels === []) {
            return '信息更新';
        }

        if (count($labels) === 1) {
            return $labels[0].'变更';
        }

        return implode('、', array_slice($labels, 0, 3)).(count($labels) > 3 ? '等变更' : '变更');
    }

    private function changeFieldLabel(string $field): string
    {
        $map = [
            'asset_tag' => '资产标签',
            'name' => '资产名称',
            'serial' => '序列号',
            'model_id' => '型号',
            'status_id' => '状态',
            'statuslabel_id' => '状态',
            'category_id' => '分类',
            'manufacturer_id' => '制造商',
            'supplier_id' => '供应商',
            'company_id' => '公司',
            'location_id' => '位置',
            'rtd_location_id' => '默认位置',
            'purchase_cost' => '采购价格',
            'order_number' => '订单号',
            'purchase_date' => '购买日期',
            'notes' => '备注',
            'expected_checkin' => '预计归还日期',
            'last_checkout' => '最近借出时间',
            'last_checkin' => '最近归还时间',
            'assigned_to' => '当前使用人/归属',
            'assigned_type' => '签出对象类型',
            'assignedTo' => '当前使用人',
            'warranty_months' => '保修月数',
            'requestable' => '是否允许申请',
            'byod' => '是否自带设备',
        ];

        return $map[$field] ?? str_replace('_', ' ', $field);
    }

    private function formatChangeValue(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $modelName = match ($field) {
            'model_id' => $this->findModelName(AssetModel::class, $value),
            'status_id', 'statuslabel_id' => $this->findModelName(Statuslabel::class, $value),
            'category_id' => $this->findModelName(Category::class, $value),
            'manufacturer_id' => $this->findModelName(Manufacturer::class, $value),
            'supplier_id' => $this->findModelName(Supplier::class, $value),
            'company_id' => $this->findModelName(Company::class, $value),
            'location_id', 'rtd_location_id' => $this->findModelName(Location::class, $value),
            'assigned_to' => $this->findAssignedName($value),
            'assigned_type' => $this->assignedTypeLabel($value),
            'requestable', 'byod' => (bool) $value ? '是' : '否',
            default => null,
        };

        return $this->shortenValue($modelName ?? $this->stringifyValue($value));
    }

    private function findModelName(string $class, $id): ?string
    {
        if (!is_scalar($id)) {
            return null;
        }

        $model = $class::find($id);

        return $model ? (string) $model->name : null;
    }

    private function findAssignedName($id): ?string
    {
        if (!is_scalar($id)) {
            return null;
        }

        return User::withTrashed()->find($id)?->present()->fullName()
            ?: Asset::withTrashed()->find($id)?->asset_tag
            ?: Location::withTrashed()->find($id)?->name;
    }

    private function assignedTypeLabel($value): ?string
    {
        return match ((string) $value) {
            User::class, 'App\\Models\\User' => '用户',
            Asset::class, 'App\\Models\\Asset' => '资产',
            Location::class, 'App\\Models\\Location' => '位置',
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    private function stringifyValue($value): string
    {
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '-';
        }

        return (string) $value;
    }

    private function shortenValue(string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r", "\n"], ' ', $value));

        if ($value === '') {
            return '-';
        }

        return mb_strlen($value) > 80 ? mb_substr($value, 0, 77).'...' : $value;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function hasNote(Actionlog $log): bool
    {
        return trim((string) ($log->note ?? '')) !== '';
    }

    private function resolveDisplayNote(Actionlog $log, string $title): ?string
    {
        if ($this->hasNote($log)) {
            return $this->normalizeNote((string) $log->note);
        }

        if ($title === '入库') {
            $assetNote = trim((string) data_get($log->item, 'notes', ''));
            if ($assetNote !== '') {
                return $this->normalizeNote($assetNote);
            }
        }

        return null;
    }

    private function resolveNewStatusName(Actionlog $log): string
    {
        $meta = json_decode((string) ($log->log_meta ?? ''), true);

        if (!is_array($meta)) {
            return '';
        }

        $newStatusId = data_get($meta, 'status_id.new');

        if (!$newStatusId) {
            return '';
        }

        return mb_strtolower((string) optional(Statuslabel::find($newStatusId))->name);
    }

    private function matchStatusTitle(string $statusName): ?string
    {
        if ($statusName === '') {
            return null;
        }

        $map = [
            '维修完成已重新入库' => '维修完成已重新入库',
            '已换新' => '已换新',
            '借用' => '借用中',
            '维修' => '维修中',
            '退租' => '退租',
            '领用' => '领用',
            '归还' => '归还',
            '入库' => '入库',
        ];

        foreach ($map as $needle => $title) {
            if (str_contains($statusName, mb_strtolower($needle))) {
                return $title;
            }
        }

        return null;
    }

    private function headlineFor(string $title, string $kind): string
    {
        $assetMap = [
            '入库' => '📦【资产入库】',
            '领用' => '📤【资产领用】',
            '归还' => '📥【资产归还】',
            '借用中' => '🕒【资产借用中】',
            '维修中' => '🔧【资产维修中】',
            '维修完成已重新入库' => '🧰【维修完成已重新入库】',
            '已换新' => '🔁【资产已换新】',
            '退租' => '📪【资产退租】',
            '删除设备' => '🗑️【删除设备】',
            '待归还已完成' => '✅【待归还已完成】',
            '操作' => '📢【资产变动】',
        ];

        $userMap = [
            '新增用户' => '👤【新增用户】',
            '更新用户' => '✏️【更新用户】',
            '删除用户' => '🚫【删除用户】',
        ];

        if ($kind === 'user') {
            return $userMap[$title] ?? "👤【{$title}】";
        }

        return $assetMap[$title] ?? "📢【{$title}】";
    }

    private function normalizeNote(string $note): string
    {
        $note = str_replace(["\r\n", "\r"], "\n", trim($note));

        return preg_replace("/\n+/", "\n", $note) ?? $note;
    }

    private function assetTitle($asset): string
    {
        $name = (string) data_get($asset, 'name', '-');
        $assetTag = (string) data_get($asset, 'asset_tag', '-');
        $modelName = (string) data_get($asset, 'model.name', '');

        return trim($name.' ('.$assetTag.')'.($modelName !== '' ? ' - '.$modelName : ''));
    }

    private function assetLink($asset, string $label): string
    {
        $url = $this->assetUrl($asset);

        return $url !== '' ? '['.$label.']('.$url.')' : $label;
    }

    private function assetUrl($asset): string
    {
        $id = data_get($asset, 'id');

        if (! $id) {
            return '';
        }

        $baseUrl = rtrim((string) config('app.url'), '/');

        return ($baseUrl !== '' ? $baseUrl : url('')).'/hardware/'.$id;
    }

    private function formatAttachmentLines(Actionlog $log, $asset, bool $blockquote = false): array
    {
        $log->loadMissing('attachments');
        $attachments = $log->attachments ?? collect();

        if ($attachments->isEmpty()) {
            return [];
        }

        $imageCount = $attachments
            ->filter(fn ($attachment) => $this->isWecomImageCandidate($attachment))
            ->count();
        $sentCount = min($imageCount, self::WECOM_IMAGE_LIMIT);
        $otherCount = max(0, $attachments->count() - $imageCount);
        $parts = [];

        if ($sentCount > 0) {
            $parts[] = '将随消息直接发送图片 '.$sentCount.' 张';
        }

        if ($imageCount > self::WECOM_IMAGE_LIMIT) {
            $parts[] = '另有 '.($imageCount - self::WECOM_IMAGE_LIMIT).' 张请到历史记录查看';
        }

        if ($otherCount > 0) {
            $parts[] = '另有 '.$otherCount.' 个凭证请到历史记录查看';
        }

        if ($parts === []) {
            $parts[] = '请到历史记录查看';
        }

        $prefix = $blockquote ? '> **操作凭证**： ' : '操作凭证： ';

        return [$prefix.implode('，', $parts)];
    }

    private function sendAttachmentImages(Actionlog $log, string $webhook): void
    {
        $log->loadMissing('attachments');
        $attachments = $log->attachments ?? collect();
        $sentCount = 0;

        foreach ($attachments as $attachment) {
            if ($sentCount >= self::WECOM_IMAGE_LIMIT || ! $this->isWecomImageCandidate($attachment)) {
                continue;
            }

            $payload = $this->wecomImagePayload($attachment);
            if (! $payload) {
                continue;
            }

            try {
                Http::asJson()
                    ->timeout(8)
                    ->post($webhook, [
                        'msgtype' => 'image',
                        'image' => $payload,
                    ])
                    ->throw();

                $sentCount++;
            } catch (\Throwable $e) {
                Log::warning('WeCom attachment image notify failed', [
                    'action_log_id' => $log->id,
                    'attachment_id' => data_get($attachment, 'id'),
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function isWecomImageCandidate($attachment): bool
    {
        $extension = strtolower(pathinfo((string) data_get($attachment, 'filename'), PATHINFO_EXTENSION));

        return in_array($extension, self::WECOM_IMAGE_CONVERTIBLE_EXTENSIONS, true);
    }

    private function wecomImagePayload($attachment): ?array
    {
        $binary = $this->attachmentContents($attachment);

        if ($binary === null || $binary === '') {
            return null;
        }

        $extension = strtolower(pathinfo((string) data_get($attachment, 'filename'), PATHINFO_EXTENSION));
        if (strlen($binary) <= self::WECOM_IMAGE_MAX_BYTES && in_array($extension, self::WECOM_IMAGE_NATIVE_EXTENSIONS, true)) {
            return [
                'base64' => base64_encode($binary),
                'md5' => md5($binary),
            ];
        }

        $binary = $this->compressImageForWecom($binary);

        if ($binary === null || strlen($binary) > self::WECOM_IMAGE_MAX_BYTES) {
            return null;
        }

        return [
            'base64' => base64_encode($binary),
            'md5' => md5($binary),
        ];
    }

    private function attachmentContents($attachment): ?string
    {
        $path = (string) data_get($attachment, 'path');

        if ($path === '' || ! Storage::exists($path)) {
            return null;
        }

        return Storage::get($path);
    }

    private function compressImageForWecom(string $binary): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scales = [1, 0.85, 0.7, 0.55, 0.4, 0.3];
        $qualities = [85, 75, 65, 55, 45];

        foreach ($scales as $scale) {
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            if (! $canvas) {
                continue;
            }

            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            foreach ($qualities as $quality) {
                ob_start();
                imagejpeg($canvas, null, $quality);
                $compressed = ob_get_clean();

                if ($compressed !== false && strlen($compressed) <= self::WECOM_IMAGE_MAX_BYTES) {
                    imagedestroy($canvas);
                    imagedestroy($source);

                    return $compressed;
                }
            }

            imagedestroy($canvas);
        }

        imagedestroy($source);

        return null;
    }

    private function attachmentLink($asset, $attachment, string $label): string
    {
        $assetId = data_get($asset, 'id');
        $attachmentId = data_get($attachment, 'id');

        if (! $assetId || ! $attachmentId) {
            return $label;
        }

        $baseUrl = rtrim((string) config('app.url'), '/');
        $path = route('hardware.actionlog-attachments.show', [
            'asset' => $assetId,
            'attachment' => $attachmentId,
        ], false);

        return '['.$this->escapeMarkdownLinkLabel($label).']('.($baseUrl !== '' ? $baseUrl : url('')).$path.')';
    }

    private function escapeMarkdownLinkLabel(string $label): string
    {
        return str_replace([']', "\r", "\n"], ['）', ' ', ' '], $label);
    }

    private function linkAssetMentions(string $note): string
    {
        if ($note === '' || ! preg_match_all('/@([A-Za-z0-9][A-Za-z0-9._-]*)/u', $note, $matches)) {
            return $note;
        }

        $assetTags = array_values(array_unique($matches[1]));
        $assets = Asset::whereIn('asset_tag', $assetTags)
            ->get()
            ->keyBy('asset_tag');

        return preg_replace_callback('/@([A-Za-z0-9][A-Za-z0-9._-]*)/u', function (array $match) use ($assets) {
            $asset = $assets->get($match[1]);

            if (! $asset) {
                return $match[0];
            }

            return $this->assetLink($asset, $match[0]);
        }, $note) ?? $note;
    }

    private function assetSummary($asset): string
    {
        $assetTag = (string) data_get($asset, 'asset_tag', '-');
        $assetName = (string) data_get($asset, 'name', '');
        $serial = (string) data_get($asset, 'serial', '');

        $summary = $assetTag;

        if ($assetName !== '') {
            $summary .= ' - '.$assetName;
        }

        if ($serial !== '') {
            $summary .= ' / SN：'.$serial;
        }

        return $summary;
    }

    private function displayName($model): string
    {
        return (string) (
            data_get($model, 'name')
            ?? trim((string) (data_get($model, 'first_name', '').' '.data_get($model, 'last_name', '')))
            ?: data_get($model, 'username', '-')
        );
    }

    private function formatTime(Actionlog $log): string
    {
        $time = $log->created_at ?? now();

        return $time->format('Y-m-d H:i:s');
    }
}
