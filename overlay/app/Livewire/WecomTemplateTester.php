<?php

namespace App\Livewire;

use App\Models\Asset;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class WecomTemplateTester extends Component
{
    public array $message_types = [
        '资产变动',
        '资产领用',
        '资产归还',
        '待归还',
        '待归还已完成',
        '资产退租',
        '移交设备',
        '自定义',
    ];

    public string $message_type = '资产变动';
    public string $custom_title = '';
    public string $operator_name = '';
    public string $target_name = '';
    public string $asset_search = '';
    public array $asset_results = [];
    public ?int $asset_id = null;
    public string $asset_tag = '';
    public string $asset_name = '';
    public string $serial = '';
    public string $model_name = '';
    public string $status_name = '';
    public string $location_name = '';
    public string $purchase_cost = '';
    public string $assigned_name = '';
    public string $asset_url = '';
    public string $note = '';
    public string $mention_search = '';
    public array $mention_results = [];
    public array $mentions = [];
    public array $changes = [
        ['label' => '序列号', 'old' => '', 'new' => ''],
        ['label' => '位置', 'old' => '', 'new' => ''],
        ['label' => '采购价格', 'old' => '', 'new' => ''],
        ['label' => '状态', 'old' => '', 'new' => ''],
        ['label' => '预计归还日期', 'old' => '', 'new' => ''],
    ];

    public function mount(): void
    {
        $this->ensureSuperuser();

        $user = auth()->user();
        $this->operator_name = $user?->present()->fullName() ?: ($user?->username ?: 'Administrator');
    }

    public function render()
    {
        $this->ensureSuperuser();

        return view('livewire.wecom-template-tester', [
            'preview' => $this->previewText(),
            'webhookConfigured' => $this->webhookUrl() !== '',
        ]);
    }

    public function searchAssets(): void
    {
        $this->ensureSuperuser();

        $this->asset_results = $this->findAssets($this->asset_search);
    }

    public function selectAsset(int $assetId): void
    {
        $this->ensureSuperuser();

        $asset = Asset::with(['model', 'model.category', 'location', 'assetstatus', 'assignedTo'])
            ->find($assetId);

        if (! $asset) {
            session()->flash('error', '没有找到这台资产。');
            return;
        }

        $this->asset_id = $asset->id;
        $this->asset_tag = (string) $asset->asset_tag;
        $this->asset_name = (string) ($asset->name ?: '-');
        $this->serial = (string) ($asset->serial ?: '-');
        $this->model_name = (string) data_get($asset, 'model.name', '-');
        $this->status_name = (string) data_get($asset, 'assetstatus.name', '-');
        $this->location_name = (string) data_get($asset, 'location.name', '-');
        $this->purchase_cost = $asset->purchase_cost !== null ? (string) $asset->purchase_cost : '-';
        $this->assigned_name = $this->displayName($asset->assignedTo);
        $this->asset_url = $this->assetUrl($asset);
        $this->asset_search = $this->asset_tag;
        $this->asset_results = [];

        $this->changes[0]['old'] = $this->serial === '-' ? '' : $this->serial;
        $this->changes[1]['old'] = $this->location_name === '-' ? '' : $this->location_name;
        $this->changes[2]['old'] = $this->purchase_cost === '-' ? '' : $this->purchase_cost;
        $this->changes[3]['old'] = $this->status_name === '-' ? '' : $this->status_name;
    }

    public function searchMentionAssets(): void
    {
        $this->ensureSuperuser();

        $this->mention_results = $this->findAssets($this->mention_search);
    }

    public function addMentionAsset(int $assetId): void
    {
        $this->ensureSuperuser();

        $asset = Asset::with(['model'])->find($assetId);

        if (! $asset) {
            return;
        }

        foreach ($this->mentions as $mention) {
            if ((int) $mention['id'] === $asset->id) {
                $this->mention_results = [];
                $this->mention_search = '';
                return;
            }
        }

        $this->mentions[] = [
            'id' => $asset->id,
            'asset_tag' => (string) $asset->asset_tag,
            'name' => (string) ($asset->name ?: '-'),
            'model' => (string) data_get($asset, 'model.name', '-'),
            'url' => $this->assetUrl($asset),
        ];

        $this->mention_results = [];
        $this->mention_search = '';
    }

    public function removeMentionAsset(int $assetId): void
    {
        $this->ensureSuperuser();

        $this->mentions = array_values(array_filter(
            $this->mentions,
            fn (array $mention) => (int) $mention['id'] !== $assetId
        ));
    }

    public function addChange(): void
    {
        $this->ensureSuperuser();

        $this->changes[] = ['label' => '', 'old' => '', 'new' => ''];
    }

    public function removeChange(int $index): void
    {
        $this->ensureSuperuser();

        unset($this->changes[$index]);
        $this->changes = array_values($this->changes);
    }

    public function sendTest(): void
    {
        $this->ensureSuperuser();

        $webhook = $this->webhookUrl();

        if ($webhook === '') {
            session()->flash('error', '企业微信 webhook 未配置，不能发送测试消息。');
            return;
        }

        if (trim($this->asset_tag) === '') {
            session()->flash('error', '至少需要填写一个资产标签。');
            return;
        }

        try {
            Http::asJson()
                ->timeout(5)
                ->post($webhook, [
                    'msgtype' => 'markdown',
                    'markdown' => [
                        'content' => $this->previewText(),
                    ],
                ])
                ->throw();

            session()->flash('success', '企业微信 DIY 测试消息已发送。');
        } catch (\Throwable $exception) {
            report($exception);
            session()->flash('error', '发送失败：'.$exception->getMessage());
        }
    }

    private function findAssets(string $keyword): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        return Asset::with(['model', 'location', 'assetstatus'])
            ->where(function ($query) use ($keyword) {
                $query->where('asset_tag', 'like', '%'.$keyword.'%')
                    ->orWhere('serial', 'like', '%'.$keyword.'%')
                    ->orWhere('name', 'like', '%'.$keyword.'%')
                    ->orWhereHas('model', function ($modelQuery) use ($keyword) {
                        $modelQuery->where('name', 'like', '%'.$keyword.'%')
                            ->orWhere('model_number', 'like', '%'.$keyword.'%');
                    });
            })
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Asset $asset) => [
                'id' => $asset->id,
                'asset_tag' => (string) $asset->asset_tag,
                'name' => (string) ($asset->name ?: '-'),
                'serial' => (string) ($asset->serial ?: '-'),
                'model' => (string) data_get($asset, 'model.name', '-'),
                'status' => (string) data_get($asset, 'assetstatus.name', '-'),
                'location' => (string) data_get($asset, 'location.name', '-'),
            ])
            ->toArray();
    }

    private function previewText(): string
    {
        $type = trim($this->custom_title) !== '' ? trim($this->custom_title) : $this->message_type;
        $assetLabel = $this->asset_tag !== ''
            ? $this->assetLink($this->asset_tag, $this->asset_url)
            : '-';
        $assetTitle = trim(($this->asset_name ?: '资产').' ('.$this->asset_tag.')'.($this->model_name ? ' - '.$this->model_name : ''));

        $lines = [
            '🧪【测试：'.$type.'】',
            $this->asset_url !== '' ? $this->assetLink($assetTitle, $this->asset_url) : $assetTitle,
            '',
            '> 资产标签： '.$assetLabel,
            '> 设备名称： '.($this->asset_name ?: '-'),
            '> 序列号： '.($this->serial ?: '-'),
            '> 设备型号： '.($this->model_name ?: '-'),
            '> 当前状态： '.($this->status_name ?: '-'),
            '> 当前位置： '.($this->location_name ?: '-'),
            '> 采购价格： '.($this->purchase_cost ?: '-'),
            '> 当前使用人： '.($this->assigned_name ?: '-'),
            '> 操作人： '.($this->operator_name ?: '-'),
        ];

        if (trim($this->target_name) !== '') {
            $lines[] = '> '.$this->targetLabel().'： '.trim($this->target_name);
        }

        $changeLines = $this->changeLines();
        if ($changeLines !== []) {
            $lines[] = '';
            $lines[] = '模拟变更：';
            foreach ($changeLines as $line) {
                $lines[] = $line;
            }
        }

        if ($this->mentions !== []) {
            $lines[] = '';
            $lines[] = '关联资产：';
            foreach ($this->mentions as $mention) {
                $lines[] = '- '.$this->assetLink('@'.$mention['asset_tag'], $mention['url'])
                    .' - '.$mention['name'].' / '.$mention['model'];
            }
        }

        if (trim($this->note) !== '') {
            $lines[] = '';
            $lines[] = '备注： '.$this->linkAssetMentions(trim($this->note));
        }

        $lines[] = '';
        $lines[] = '测试时间： '.now()->format('Y-m-d H:i:s');
        $lines[] = '<font color="comment">这是一条测试消息，不会修改 Snipe-IT 资产数据。</font>';

        return implode("\n", $lines);
    }

    private function changeLines(): array
    {
        $lines = [];

        foreach ($this->changes as $change) {
            $label = trim((string) ($change['label'] ?? ''));
            $old = trim((string) ($change['old'] ?? ''));
            $new = trim((string) ($change['new'] ?? ''));

            if ($label === '' || ($old === '' && $new === '')) {
                continue;
            }

            $lines[] = '- '.$label.'：'.($old !== '' ? $old : '-').' → <font color="warning">'.($new !== '' ? $new : '-').'</font>';
        }

        return $lines;
    }

    private function targetLabel(): string
    {
        return match ($this->message_type) {
            '资产领用' => '领用人',
            '资产归还' => '归还人',
            '待归还', '待归还已完成' => '当前使用人',
            '移交设备' => '接收人',
            default => '操作对象',
        };
    }

    private function linkAssetMentions(string $note): string
    {
        $manualMentions = collect($this->mentions)->keyBy('asset_tag');

        if (preg_match_all('/@([A-Za-z0-9][A-Za-z0-9._-]*)/u', $note, $matches)) {
            $tags = array_values(array_unique($matches[1]));
            $assets = Asset::whereIn('asset_tag', $tags)->get()->keyBy('asset_tag');

            $note = preg_replace_callback('/@([A-Za-z0-9][A-Za-z0-9._-]*)/u', function (array $match) use ($manualMentions, $assets) {
                $tag = $match[1];
                $manual = $manualMentions->get($tag);

                if ($manual) {
                    return $this->assetLink('@'.$tag, $manual['url']);
                }

                $asset = $assets->get($tag);

                return $asset ? $this->assetLink('@'.$tag, $this->assetUrl($asset)) : $match[0];
            }, $note) ?? $note;
        }

        return $note;
    }

    private function assetLink(string $label, string $url): string
    {
        return $url !== '' ? '['.$label.']('.$url.')' : $label;
    }

    private function assetUrl($asset): string
    {
        $id = data_get($asset, 'id');

        if (! $id) {
            return '';
        }

        $baseUrl = rtrim((string) config('app.url'), '/');

        return ($baseUrl !== '' ? $baseUrl : rtrim(url(''), '/')).'/hardware/'.$id;
    }

    private function displayName($model): string
    {
        if (! $model) {
            return '-';
        }

        $fullName = trim((string) (data_get($model, 'first_name', '').' '.data_get($model, 'last_name', '')));

        if ($fullName !== '') {
            return $fullName;
        }

        return (string) (
            data_get($model, 'name')
            ?: data_get($model, 'asset_tag')
            ?: data_get($model, 'username')
            ?: '-'
        );
    }

    private function webhookUrl(): string
    {
        $settings = Setting::getSettings();

        return trim((string) (
            config('services.wecom.webhook')
            ?: config('services.wecom.webhook_url')
            ?: ($settings?->webhook_selected === 'wecom' ? $settings->webhook_endpoint : '')
        ));
    }

    private function ensureSuperuser(): void
    {
        abort_unless(auth()->check() && auth()->user()->isSuperUser(), 403);
    }
}
