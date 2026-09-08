<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\AssetPendingReturn;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class SendPendingReturnAlerts extends Command
{
    protected $signature = 'snipeit:pending-return-alerts
        {--scope=all : Reminder scope: all, overdue, or today}
        {--dry-run : Preview the message without sending it}';

    protected $description = 'Send WeCom reminders for pending-return assets due today or overdue.';

    public function handle(): int
    {
        $today = Carbon::today();
        $scope = $this->scope();
        $rows = $this->filterRowsByScope($this->dueRows($today), $today, $scope);

        if ($rows->isEmpty()) {
            $this->info('No pending-return assets matched scope: '.$scope.'.');

            return 0;
        }

        $content = $this->markdown($rows, $today, $scope);

        if ($this->option('dry-run')) {
            $this->line($content);

            return 0;
        }

        $settings = Setting::getSettings();
        $webhook = config('services.wecom.webhook_url')
            ?: ($settings?->webhook_selected === 'wecom' ? $settings->webhook_endpoint : null);

        if (! $webhook) {
            $this->warn('WeCom webhook is not configured. Use --dry-run to preview the reminder.');

            return 0;
        }

        $response = Http::acceptJson()
            ->timeout(8)
            ->post($webhook, [
                'msgtype' => 'markdown',
                'markdown' => [
                    'content' => $content,
                ],
            ]);

        if (! $response->successful()) {
            $this->error('WeCom webhook returned HTTP '.$response->status().'.');

            return 1;
        }

        $result = $response->json();

        if ((int) ($result['errcode'] ?? -1) !== 0) {
            $this->error('WeCom webhook rejected the message: '.($result['errmsg'] ?? 'unknown error'));

            return 1;
        }

        $this->info('Pending-return reminder sent. Scope: '.$scope.'. Rows: '.$rows->count());

        return 0;
    }

    private function scope(): string
    {
        $scope = (string) $this->option('scope');

        return in_array($scope, ['all', 'overdue', 'today'], true) ? $scope : 'all';
    }

    private function dueRows(Carbon $today): Collection
    {
        $manualRows = AssetPendingReturn::query()
            ->with([
                'asset.assetstatus',
                'asset.location',
                'asset.model',
                'replacementAsset',
                'user.department',
            ])
            ->open()
            ->whereNotNull('expected_return_date')
            ->whereDate('expected_return_date', '<=', $today->toDateString())
            ->get()
            ->map(function (AssetPendingReturn $pendingReturn) {
                return [
                    'source' => '手动待归还',
                    'asset' => $pendingReturn->asset,
                    'user' => $pendingReturn->user,
                    'replacementAsset' => $pendingReturn->replacementAsset,
                    'reason' => $pendingReturn->reason,
                    'note' => $pendingReturn->note,
                    'expectedDate' => $pendingReturn->expected_return_date,
                ];
            });

        $borrowedAssets = Asset::query()
            ->with(['assetstatus', 'location', 'model'])
            ->where('assigned_type', User::class)
            ->whereNotNull('assigned_to')
            ->whereNotNull('expected_checkin')
            ->whereDate('expected_checkin', '<=', $today->toDateString())
            ->whereHas('assetstatus', function ($query) {
                $query->where('name', 'like', '%借用%');
            })
            ->whereDoesntHave('pendingReturns', function ($query) {
                $query->open();
            })
            ->get();

        $users = User::withTrashed()
            ->with('department')
            ->whereIn('id', $borrowedAssets->pluck('assigned_to')->filter()->unique())
            ->get()
            ->keyBy('id');

        $borrowedRows = $borrowedAssets->map(function (Asset $asset) use ($users) {
            return [
                'source' => '借用状态',
                'asset' => $asset,
                'user' => $users->get((int) $asset->assigned_to),
                'replacementAsset' => null,
                'reason' => '状态为借用中',
                'note' => '来自借用状态，正式归还后会从待归还看板消失。',
                'expectedDate' => Carbon::parse($asset->expected_checkin),
            ];
        });

        return $manualRows
            ->merge($borrowedRows)
            ->sortBy('expectedDate')
            ->values();
    }

    private function filterRowsByScope(Collection $rows, Carbon $today, string $scope): Collection
    {
        if ($scope === 'overdue') {
            return $rows
                ->filter(fn (array $row) => Carbon::parse($row['expectedDate'])->lt($today))
                ->values();
        }

        if ($scope === 'today') {
            return $rows
                ->filter(fn (array $row) => Carbon::parse($row['expectedDate'])->isSameDay($today))
                ->values();
        }

        return $rows->values();
    }

    private function markdown(Collection $rows, Carbon $today, string $scope): string
    {
        $overdueCount = $rows->filter(fn (array $row) => Carbon::parse($row['expectedDate'])->lt($today))->count();
        $todayCount = $rows->count() - $overdueCount;
        $lines = [
            '**'.$this->messageTitle($scope).'**',
            '> 日期：'.$today->toDateString(),
            '> 已逾期：'.$overdueCount.'；今天应归还：'.$todayCount,
            '',
        ];

        foreach ($rows->take(12) as $row) {
            $asset = $row['asset'];
            $user = $row['user'];
            $expectedDate = Carbon::parse($row['expectedDate']);
            $status = $expectedDate->lt($today)
                ? '已逾期 '.max(1, $expectedDate->diffInDays($today)).' 天'
                : '今天到期';

            $lines[] = '> '.$status.'｜'.$row['source'];
            $lines[] = '> 资产：'.$this->assetMarkdown($asset).'；SN：'.$this->plain($asset?->serial ?: '-');
            $lines[] = '> 使用人：'.$this->plain($user ? $user->present()->fullName() : '-')
                .'；部门：'.$this->plain(optional($user?->department)->name ?: '-');

            if ($row['replacementAsset']) {
                $lines[] = '> 关联新设备：'.$this->assetMarkdown($row['replacementAsset']);
            }

            $lines[] = '> 原因：'.$this->plain($row['reason'] ?: '-');

            if ($row['note']) {
                $lines[] = '> 备注：'.$this->plain($row['note']);
            }

            $lines[] = '';
        }

        if ($rows->count() > 12) {
            $lines[] = '> 还有 '.($rows->count() - 12).' 条未展示，请进入待归还看板查看。';
        }

        return $this->truncate(trim(implode("\n", $lines)));
    }

    private function messageTitle(string $scope): string
    {
        return [
            'overdue' => '待归还逾期提醒',
            'today' => '设备归还提醒',
        ][$scope] ?? '待归还到期提醒';
    }

    private function assetMarkdown(?Asset $asset): string
    {
        if (! $asset) {
            return '-';
        }

        return '['.$this->plain($asset->asset_tag).']('.route('hardware.show', $asset).')';
    }

    private function plain(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));

        return $value === '' ? '-' : $value;
    }

    private function truncate(string $content): string
    {
        if (strlen($content) <= 3900) {
            return $content;
        }

        $truncated = function_exists('mb_strcut')
            ? mb_strcut($content, 0, 3897, 'UTF-8')
            : substr($content, 0, 3897);

        return rtrim($truncated).'...';
    }
}
