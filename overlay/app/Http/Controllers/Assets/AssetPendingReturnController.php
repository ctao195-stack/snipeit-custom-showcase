<?php

namespace App\Http\Controllers\Assets;

use App\Events\CheckoutableCheckedIn;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetPendingReturn;
use App\Models\CheckoutAcceptance;
use App\Models\LicenseSeat;
use App\Models\Statuslabel;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetPendingReturnController extends Controller
{
    private const SOURCE_MANUAL = 'manual';
    private const SOURCE_REMINDER = 'reminder';
    private const SOURCE_BORROWED = 'borrowed_status';

    public function index(Request $request): View
    {
        $this->authorize('index', Asset::class);

        [$search, $dueFilter, $sourceFilter, $today, $weekEnd] = $this->listFilters($request);
        $summary = $this->buildDueSummary($dueFilter, $sourceFilter, $today, $weekEnd);
        $sourceSummary = $this->buildSourceSummary();
        $rows = $this->listRows($search, $dueFilter, $sourceFilter, $today, $weekEnd);
        $pendingReturns = $this->paginateRows($this->sortRows($rows), $request);

        return view('hardware.pending-returns-index', [
            'pendingReturns' => $pendingReturns,
            'search' => $search,
            'dueFilter' => $dueFilter,
            'sourceFilter' => $sourceFilter,
            'summary' => $summary,
            'sourceSummary' => $sourceSummary,
            'today' => $today,
            'weekEnd' => $weekEnd,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('index', Asset::class);

        [$search, $dueFilter, $sourceFilter, $today, $weekEnd] = $this->listFilters($request);
        $rows = $this->sortRows($this->listRows($search, $dueFilter, $sourceFilter, $today, $weekEnd));
        $filename = 'pending-returns-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $today) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                '来源',
                '资产标签',
                '资产名称',
                '序列号',
                '状态',
                '位置',
                '当前使用人',
                '部门',
                '关联事项',
                '关联事项SN',
                '待归还原因',
                '备注',
                '预计归还日期',
                '到期状态',
                '新增于/借出时间',
                '创建者',
            ]);

            foreach ($rows as $row) {
                fputcsv($output, $this->exportRow($row, $today));
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function listFilters(Request $request): array
    {
        $search = trim((string) $request->input('search'));
        $dueFilter = (string) $request->input('due', 'all');
        $sourceFilter = (string) $request->input('source', 'all');
        $today = Carbon::today();
        $weekEnd = $today->copy()->addDays(7);

        if ($dueFilter === 'borrowed') {
            $dueFilter = 'all';
            $sourceFilter = 'borrowed';
        }

        if (! in_array($dueFilter, ['all', 'overdue', 'today', 'week', 'no_date'], true)) {
            $dueFilter = 'all';
        }

        if (! in_array($sourceFilter, ['all', self::SOURCE_MANUAL, self::SOURCE_REMINDER, 'borrowed'], true)) {
            $sourceFilter = 'all';
        }

        return [$search, $dueFilter, $sourceFilter, $today, $weekEnd];
    }

    private function listRows(string $search, string $dueFilter, string $sourceFilter, Carbon $today, Carbon $weekEnd): Collection
    {
        $rows = collect();

        if ($sourceFilter !== 'borrowed') {
            $rows = $rows->merge($this->pendingReturnRows($search, $dueFilter, $sourceFilter, $today, $weekEnd));
        }

        if (in_array($sourceFilter, ['all', 'borrowed'], true)) {
            $rows = $rows->merge($this->borrowedAssetRows($search, $dueFilter, $today, $weekEnd));
        }

        return $rows;
    }

    private function buildDueSummary(string $dueFilter, string $sourceFilter, Carbon $today, Carbon $weekEnd): array
    {
        return [
            'all' => $this->countRowsForDateFilter('all', $sourceFilter, $today, $weekEnd),
            'overdue' => $this->countRowsForDateFilter('overdue', $sourceFilter, $today, $weekEnd),
            'today' => $this->countRowsForDateFilter('today', $sourceFilter, $today, $weekEnd),
            'week' => $this->countRowsForDateFilter('week', $sourceFilter, $today, $weekEnd),
            'no_date' => $this->countRowsForDateFilter('no_date', $sourceFilter, $today, $weekEnd),
            'active' => $dueFilter,
        ];
    }

    private function buildSourceSummary(): array
    {
        $manualCount = AssetPendingReturn::query()
            ->open()
            ->where(function ($query) {
                $query->whereNull('source')
                    ->orWhere('source', self::SOURCE_MANUAL);
            })
            ->count();
        $reminderCount = AssetPendingReturn::query()
            ->open()
            ->where('source', self::SOURCE_REMINDER)
            ->count();
        $borrowedCount = $this->borrowedAssetQuery()->count();

        return [
            'all' => $manualCount + $reminderCount + $borrowedCount,
            'manual' => $manualCount,
            'reminder' => $reminderCount,
            'borrowed' => $borrowedCount,
        ];
    }

    private function countRowsForDateFilter(string $dueFilter, string $sourceFilter, Carbon $today, Carbon $weekEnd): int
    {
        $count = 0;

        if ($sourceFilter !== 'borrowed') {
            $query = AssetPendingReturn::query()->open();
            $this->applyPendingReturnSourceFilter($query, $sourceFilter);
            $this->applyDateFilter($query, $dueFilter, 'expected_return_date', $today, $weekEnd);
            $count += $query->count();
        }

        if (in_array($sourceFilter, ['all', 'borrowed'], true)) {
            $query = $this->borrowedAssetQuery();
            $this->applyDateFilter($query, $dueFilter, 'expected_checkin', $today, $weekEnd);
            $count += $query->count();
        }

        return $count;
    }

    private function pendingReturnRows(string $search, string $dueFilter, string $sourceFilter, Carbon $today, Carbon $weekEnd): Collection
    {
        $query = AssetPendingReturn::query()
            ->with([
                'asset.assetstatus',
                'asset.location',
                'asset.model.category',
                'replacementAsset',
                'user.department',
                'creator',
            ])
            ->open();

        $this->applyPendingReturnSearch($query, $search);
        $this->applyPendingReturnSourceFilter($query, $sourceFilter);
        $this->applyDateFilter($query, $dueFilter, 'expected_return_date', $today, $weekEnd);

        return $query->get()->map(function (AssetPendingReturn $pendingReturn) {
            $source = $pendingReturn->source ?: self::SOURCE_MANUAL;
            $sourceKey = $source === self::SOURCE_REMINDER ? self::SOURCE_REMINDER : self::SOURCE_MANUAL;

            return [
                'source' => $sourceKey,
                'asset' => $pendingReturn->asset,
                'replacementAsset' => $pendingReturn->replacementAsset,
                'user' => $pendingReturn->user,
                'reason' => $pendingReturn->reason,
                'note' => $pendingReturn->note,
                'expectedDate' => $pendingReturn->expected_return_date,
                'createdAt' => $pendingReturn->created_at,
                'creator' => $pendingReturn->creator,
                'sourceLabel' => trans('admin/hardware/message.pending_return.'.($sourceKey === self::SOURCE_REMINDER ? 'source_reminder' : 'source_pending_return')),
            ];
        });
    }

    private function borrowedAssetRows(string $search, string $dueFilter, Carbon $today, Carbon $weekEnd): Collection
    {
        $query = $this->borrowedAssetQuery();

        $this->applyBorrowedAssetSearch($query, $search);
        $this->applyDateFilter($query, $dueFilter, 'expected_checkin', $today, $weekEnd);

        $assets = $query->get();
        $users = User::withTrashed()
            ->with('department')
            ->whereIn('id', $assets->pluck('assigned_to')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $assets->map(function (Asset $asset) use ($users) {
            return [
                'source' => self::SOURCE_BORROWED,
                'asset' => $asset,
                'replacementAsset' => null,
                'user' => $users->get((int) $asset->assigned_to),
                'reason' => trans('admin/hardware/message.pending_return.borrowed_reason'),
                'note' => trans('admin/hardware/message.pending_return.borrowed_note'),
                'expectedDate' => $asset->expected_checkin ? Carbon::parse($asset->expected_checkin) : null,
                'createdAt' => $asset->last_checkout ?: $asset->updated_at,
                'creator' => null,
                'sourceLabel' => trans('admin/hardware/message.pending_return.source_borrowed_status_plain'),
            ];
        });
    }

    private function borrowedAssetQuery()
    {
        return Asset::query()
            ->with([
                'assetstatus',
                'location',
                'model.category',
                'assignedTo.department',
            ])
            ->where('assigned_type', User::class)
            ->whereNotNull('assigned_to')
            ->whereHas('assetstatus', function ($query) {
                $query->where('name', 'like', '%借用%');
            })
            ->whereDoesntHave('pendingReturns', function ($query) {
                $query->open();
            });
    }

    private function applyPendingReturnSearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($query) use ($search) {
            $query->where('reason', 'like', '%'.$search.'%')
                ->orWhere('note', 'like', '%'.$search.'%')
                ->orWhereHas('asset', function ($query) use ($search) {
                    $query->where('asset_tag', 'like', '%'.$search.'%')
                        ->orWhere('serial', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                })
                ->orWhereHas('replacementAsset', function ($query) use ($search) {
                    $query->where('asset_tag', 'like', '%'.$search.'%')
                        ->orWhere('serial', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                })
                ->orWhereHas('user', function ($query) use ($search) {
                    $query->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('username', 'like', '%'.$search.'%');
                });
        });
    }

    private function applyPendingReturnSourceFilter($query, string $sourceFilter): void
    {
        if ($sourceFilter === self::SOURCE_REMINDER) {
            $query->where('source', self::SOURCE_REMINDER);

            return;
        }

        if ($sourceFilter === self::SOURCE_MANUAL) {
            $query->where(function ($query) {
                $query->whereNull('source')
                    ->orWhere('source', self::SOURCE_MANUAL);
            });
        }
    }

    private function applyBorrowedAssetSearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($query) use ($search) {
            $query->where('asset_tag', 'like', '%'.$search.'%')
                ->orWhere('serial', 'like', '%'.$search.'%')
                ->orWhere('name', 'like', '%'.$search.'%')
                ->orWhereHas('assignedTo', function ($query) use ($search) {
                    $query->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('username', 'like', '%'.$search.'%');
                });
        });
    }

    private function applyDateFilter($query, string $dueFilter, string $column, Carbon $today, Carbon $weekEnd): void
    {
        if ($dueFilter === 'overdue') {
            $query->whereDate($column, '<', $today->toDateString());
        } elseif ($dueFilter === 'today') {
            $query->whereDate($column, $today->toDateString());
        } elseif ($dueFilter === 'week') {
            $query->whereDate($column, '>', $today->toDateString())
                ->whereDate($column, '<=', $weekEnd->toDateString());
        } elseif ($dueFilter === 'no_date') {
            $query->whereNull($column);
        }
    }

    private function sortRows(Collection $rows): Collection
    {
        return $rows->sort(function (array $left, array $right) {
            $leftDate = $left['expectedDate'];
            $rightDate = $right['expectedDate'];

            if ((bool) $leftDate !== (bool) $rightDate) {
                return $leftDate ? -1 : 1;
            }

            if ($leftDate && $rightDate && ! $leftDate->isSameDay($rightDate)) {
                return $leftDate->lt($rightDate) ? -1 : 1;
            }

            $leftCreated = $left['createdAt'] ? Carbon::parse($left['createdAt'])->timestamp : 0;
            $rightCreated = $right['createdAt'] ? Carbon::parse($right['createdAt'])->timestamp : 0;

            return $rightCreated <=> $leftCreated;
        })->values();
    }

    private function paginateRows(Collection $rows, Request $request): LengthAwarePaginator
    {
        $perPage = 50;
        $page = max(1, (int) $request->input('page', 1));

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->only('search', 'due', 'source'),
            ]
        );
    }

    private function exportRow(array $row, Carbon $today): array
    {
        $asset = $row['asset'];
        $replacementAsset = $row['replacementAsset'];
        $user = $row['user'];
        $creator = $row['creator'];
        $expectedDate = $row['expectedDate'] ? Carbon::parse($row['expectedDate']) : null;
        $createdAt = $row['createdAt'] ? Carbon::parse($row['createdAt']) : null;
        $assetName = $asset ? ($asset->name ?: optional($asset->model)->name ?: '') : '';
        $relatedContext = $this->exportRelatedContext($row);
        $relatedSerial = $replacementAsset?->serial ?: '';

        return [
            $row['sourceLabel'] ?? '',
            $asset?->asset_tag ?: '',
            $assetName,
            $asset?->serial ?: '',
            optional($asset?->assetstatus)->name ?: '',
            optional($asset?->location)->name ?: '',
            $user ? $user->present()->fullName() : '',
            optional($user?->department)->name ?: '',
            $relatedContext,
            $relatedSerial,
            $row['reason'] ?: '',
            $row['note'] ?: '',
            $expectedDate ? $expectedDate->toDateString() : '',
            $this->dueStatusText($expectedDate, $today),
            $createdAt ? $createdAt->format('Y-m-d H:i:s') : '',
            $creator ? $creator->present()->fullName() : '',
        ];
    }

    private function exportRelatedContext(array $row): string
    {
        $replacementAsset = $row['replacementAsset'];

        if ($replacementAsset) {
            return trim('新设备：'.($replacementAsset->asset_tag ?: '').' '.($replacementAsset->name ?: optional($replacementAsset->model)->name ?: ''));
        }

        if (($row['source'] ?? '') === self::SOURCE_BORROWED) {
            return '借用状态：由资产状态自动纳入待归还看板';
        }

        if (($row['source'] ?? '') === self::SOURCE_REMINDER) {
            return '提醒归还：仅在待归还看板提醒，不写入历史或推送';
        }

        return '当前设备：仅跟踪这台设备归还，未关联新设备';
    }

    private function dueStatusText(?Carbon $expectedDate, Carbon $today): string
    {
        if (! $expectedDate) {
            return trans('admin/hardware/message.pending_return.no_expected_date_short');
        }

        if ($expectedDate->lt($today)) {
            return trans('admin/hardware/message.pending_return.overdue_days', [
                'days' => max(1, $expectedDate->diffInDays($today)),
            ]);
        }

        if ($expectedDate->isSameDay($today)) {
            return trans('admin/hardware/message.pending_return.due_today');
        }

        if ($expectedDate->lte($today->copy()->addDays(7))) {
            return trans('admin/hardware/message.pending_return.due_in_days', [
                'days' => max(1, $today->diffInDays($expectedDate)),
            ]);
        }

        return trans('admin/hardware/message.pending_return.on_track');
    }

    public function create(Asset $asset): View|RedirectResponse
    {
        $this->authorize('update', $asset);

        if ($redirect = $this->guardPendingReturnable($asset)) {
            return $redirect;
        }

        $activePendingReturn = $asset->activePendingReturn()
            ->with(['replacementAsset.model', 'replacementAsset.assetstatus', 'replacementAsset.location'])
            ->first();

        return view('hardware.pending-return', [
            'asset' => $asset,
            'activePendingReturn' => $activePendingReturn,
            'replacementAssets' => $this->replacementAssetsFor($asset, $activePendingReturn),
            'mode' => 'standard',
            'formAction' => route('hardware.pending-return.store', $asset),
        ])->with('item', $asset);
    }

    public function createReminder(Asset $asset): View|RedirectResponse
    {
        $this->authorize('update', $asset);

        if ($redirect = $this->guardPendingReturnable($asset)) {
            return $redirect;
        }

        $activePendingReturn = $asset->activePendingReturn()->first();

        if ($activePendingReturn && ($activePendingReturn->source ?: self::SOURCE_MANUAL) !== self::SOURCE_REMINDER) {
            return redirect()->route('hardware.pending-return.create', $asset)
                ->with('warning', trans('admin/hardware/message.pending_return.reminder_existing_standard'));
        }

        return view('hardware.pending-return', [
            'asset' => $asset,
            'activePendingReturn' => $activePendingReturn,
            'replacementAssets' => collect(),
            'mode' => 'reminder',
            'formAction' => route('hardware.pending-return.reminder.store', $asset),
        ])->with('item', $asset);
    }

    public function store(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);

        if ($redirect = $this->guardPendingReturnable($asset)) {
            return $redirect;
        }

        $validated = $request->validate([
            'replacement_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'expected_return_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $currentUser = $asset->assignedTo;
        $replacementAsset = null;

        if (! empty($validated['replacement_asset_id'])) {
            $replacementAsset = Asset::findOrFail($validated['replacement_asset_id']);

            if ($replacementAsset->id === $asset->id) {
                return redirect()->route('hardware.pending-return.create', $asset)
                    ->withInput()
                    ->with('error', trans('admin/hardware/message.pending_return.same_asset'));
            }

            if ($replacementAsset->assigned_type !== User::class || (int) $replacementAsset->assigned_to !== (int) $currentUser->id) {
                return redirect()->route('hardware.pending-return.create', $asset)
                    ->withInput()
                    ->with('error', trans('admin/hardware/message.pending_return.replacement_not_assigned_to_user'));
            }
        }

        try {
            DB::transaction(function () use ($asset, $currentUser, $replacementAsset, $validated) {
                $pendingReturn = AssetPendingReturn::where('asset_id', $asset->id)
                    ->open()
                    ->lockForUpdate()
                    ->first();

                $isUpdate = (bool) $pendingReturn;

                if (! $pendingReturn) {
                    $pendingReturn = new AssetPendingReturn([
                        'asset_id' => $asset->id,
                        'created_by' => auth()->id(),
                        'status' => 'pending',
                    ]);
                }

                $pendingReturn->fill([
                    'user_id' => $currentUser->id,
                    'replacement_asset_id' => $replacementAsset?->id,
                    'reason' => $validated['reason'],
                    'expected_return_date' => $validated['expected_return_date'] ?? null,
                    'note' => $validated['note'] ?? null,
                    'source' => self::SOURCE_MANUAL,
                ]);

                if (! $pendingReturn->save()) {
                    throw new RuntimeException(trans('admin/hardware/message.pending_return.error'));
                }

                $actionlog = new Actionlog();
                $actionlog->item_type = Asset::class;
                $actionlog->item_id = $asset->id;
                $actionlog->target_type = $replacementAsset ? Asset::class : null;
                $actionlog->target_id = $replacementAsset?->id;
                $actionlog->created_by = auth()->id();
                $actionlog->note = $this->buildLogNote($asset, $currentUser, $replacementAsset, $validated, $isUpdate);
                $actionlog->action_date = now();
                $actionlog->logaction($isUpdate ? 'pending return update' : 'pending return');

                if ($replacementAsset) {
                    $actionlog->mentionedAssets()->syncWithoutDetaching([$replacementAsset->id]);
                }
            });
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('hardware.pending-return.create', $asset)
                ->withInput()
                ->with('error', trans('admin/hardware/message.pending_return.error'));
        }

        return Helper::getRedirectOption($request, $asset->id, 'Assets')
            ->with('success', trans('admin/hardware/message.pending_return.success'));
    }

    public function storeReminder(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);

        if ($redirect = $this->guardPendingReturnable($asset)) {
            return $redirect;
        }

        $activePendingReturn = $asset->activePendingReturn()->first();

        if ($activePendingReturn && ($activePendingReturn->source ?: self::SOURCE_MANUAL) !== self::SOURCE_REMINDER) {
            return redirect()->route('hardware.pending-return.create', $asset)
                ->withInput()
                ->with('warning', trans('admin/hardware/message.pending_return.reminder_existing_standard'));
        }

        $validated = $request->validate([
            'expected_return_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $currentUser = $asset->assignedTo;

        try {
            DB::transaction(function () use ($asset, $currentUser, $validated) {
                $pendingReturn = AssetPendingReturn::where('asset_id', $asset->id)
                    ->open()
                    ->lockForUpdate()
                    ->first();

                if (! $pendingReturn) {
                    $pendingReturn = new AssetPendingReturn([
                        'asset_id' => $asset->id,
                        'created_by' => auth()->id(),
                        'status' => 'pending',
                    ]);
                }

                $pendingReturn->fill([
                    'user_id' => $currentUser->id,
                    'replacement_asset_id' => null,
                    'reason' => $validated['reason'],
                    'expected_return_date' => $validated['expected_return_date'] ?? null,
                    'note' => $validated['note'] ?? null,
                    'source' => self::SOURCE_REMINDER,
                ]);

                if (! $pendingReturn->save()) {
                    throw new RuntimeException(trans('admin/hardware/message.pending_return.error'));
                }
            });
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('hardware.pending-return.reminder.create', $asset)
                ->withInput()
                ->with('error', trans('admin/hardware/message.pending_return.error'));
        }

        return Helper::getRedirectOption($request, $asset->id, 'Assets')
            ->with('success', trans('admin/hardware/message.pending_return.reminder_success'));
    }

    public function cancel(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);

        $validated = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:191'],
        ]);
        $checkedInReplacement = false;

        try {
            DB::transaction(function () use ($asset, $validated, &$checkedInReplacement) {
                $pendingReturn = AssetPendingReturn::with(['user', 'replacementAsset'])
                    ->where('asset_id', $asset->id)
                    ->open()
                    ->lockForUpdate()
                    ->first();

                if (! $pendingReturn) {
                    throw new RuntimeException(trans('admin/hardware/message.pending_return.no_active_record'));
                }

                if (($pendingReturn->source ?: self::SOURCE_MANUAL) === self::SOURCE_REMINDER) {
                    $pendingReturn->fill([
                        'status' => 'cancelled',
                        'completed_at' => now(),
                        'resolved_by' => auth()->id(),
                    ]);

                    if (! $pendingReturn->save()) {
                        throw new RuntimeException(trans('admin/hardware/message.pending_return.cancel_error'));
                    }

                    return;
                }

                $replacementResolutionNote = $this->resolveReplacementForCancelledPendingReturn(
                    $asset,
                    $pendingReturn,
                    $validated['cancel_reason'] ?? null,
                    $checkedInReplacement
                );

                $pendingReturn->fill([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'resolved_by' => auth()->id(),
                ]);

                if (! $pendingReturn->save()) {
                    throw new RuntimeException(trans('admin/hardware/message.pending_return.cancel_error'));
                }

                $actionlog = new Actionlog();
                $actionlog->item_type = Asset::class;
                $actionlog->item_id = $asset->id;
                $actionlog->target_type = $pendingReturn->replacementAsset ? Asset::class : null;
                $actionlog->target_id = $pendingReturn->replacementAsset?->id;
                $actionlog->created_by = auth()->id();
                $actionlog->note = $this->buildCancelLogNote($asset, $pendingReturn, $validated['cancel_reason'] ?? null, $replacementResolutionNote);
                $actionlog->action_date = now();
                $actionlog->logaction('pending return canceled');

                if ($pendingReturn->replacementAsset) {
                    $actionlog->mentionedAssets()->syncWithoutDetaching([$pendingReturn->replacementAsset->id]);
                }

                $pendingReturn->completed_actionlog_id = $actionlog->id;
                $pendingReturn->save();
            });
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->back()
                ->with('error', $exception instanceof RuntimeException
                ? $exception->getMessage()
                    : trans('admin/hardware/message.pending_return.cancel_error'));
        }

        return redirect()->route('hardware.pending-returns.index')
            ->with('success', trans($checkedInReplacement
                ? 'admin/hardware/message.pending_return.cancel_with_replacement_success'
                : 'admin/hardware/message.pending_return.cancel_success'));
    }

    private function resolveReplacementForCancelledPendingReturn(Asset $oldAsset, AssetPendingReturn $pendingReturn, ?string $cancelReason, bool &$checkedInReplacement): ?string
    {
        $replacementAsset = $pendingReturn->replacementAsset;

        if (! $replacementAsset) {
            return null;
        }

        $replacementAsset->refresh()->loadMissing(['assignedTo', 'licenseseats']);
        $user = $pendingReturn->user;
        $userName = $user?->present()->fullName() ?: '-';

        if ($replacementAsset->assigned_type === User::class && $user && (int) $replacementAsset->assigned_to === (int) $user->id) {
            $this->checkInReplacementAsset($oldAsset, $replacementAsset, $pendingReturn, $cancelReason);
            $checkedInReplacement = true;

            return trans('admin/hardware/message.pending_return.replacement_checked_in_note', [
                'asset_tag' => $replacementAsset->asset_tag,
            ]);
        }

        if (! $replacementAsset->assigned_to) {
            return trans('admin/hardware/message.pending_return.replacement_already_clear_note', [
                'asset_tag' => $replacementAsset->asset_tag,
                'user' => $userName,
            ]);
        }

        throw new RuntimeException(trans('admin/hardware/message.pending_return.replacement_owner_mismatch', [
            'asset_tag' => $replacementAsset->asset_tag,
            'user' => $userName,
        ]));
    }

    private function checkInReplacementAsset(Asset $oldAsset, Asset $replacementAsset, AssetPendingReturn $pendingReturn, ?string $cancelReason): void
    {
        if (is_null($target = $replacementAsset->assignedTo)) {
            return;
        }

        $checkinAt = now()->format('Y-m-d H:i:s');
        $originalValues = $replacementAsset->getRawOriginal();

        $replacementAsset->expected_checkin = null;
        $replacementAsset->assignedTo()->disassociate();
        $replacementAsset->accepted = null;
        $replacementAsset->last_checkin = $checkinAt;
        $replacementAsset->location_id = $replacementAsset->rtd_location_id;

        if ($returnedStatusId = $this->returnedStatusId()) {
            $replacementAsset->status_id = $returnedStatusId;
        }

        $replacementAsset->licenseseats->each(function (LicenseSeat $seat) {
            $seat->update(['assigned_to' => null]);
        });

        $this->deletePendingAcceptances($replacementAsset);

        if (! $replacementAsset->save()) {
            throw new RuntimeException(trans('admin/hardware/message.pending_return.replacement_checkin_error'));
        }

        event(new CheckoutableCheckedIn(
            $replacementAsset,
            $target,
            auth()->user(),
            $this->buildReplacementCheckinNote($oldAsset, $replacementAsset, $pendingReturn, $cancelReason),
            $checkinAt,
            $originalValues
        ));

        $checkinActionlog = Actionlog::where('item_type', Asset::class)
            ->where('item_id', $replacementAsset->id)
            ->where('action_type', 'checkin from')
            ->where('created_by', auth()->id())
            ->latest('id')
            ->first();

        if ($checkinActionlog) {
            $checkinActionlog->mentionedAssets()->syncWithoutDetaching([$oldAsset->id]);
        }
    }

    private function deletePendingAcceptances(Asset $asset): void
    {
        CheckoutAcceptance::pending()->whereHasMorph(
            'checkoutable',
            [Asset::class],
            function (Builder $query) use ($asset) {
                $query->where('id', $asset->id);
            }
        )->get()->each(function (CheckoutAcceptance $acceptance) {
            $acceptance->delete();
        });
    }

    private function returnedStatusId(): ?int
    {
        return Statuslabel::query()->where('name', '归还')->value('id')
            ?: Statuslabel::query()->where('name', 'like', '%归还%')->value('id');
    }

    private function replacementAssetsFor(Asset $asset, ?AssetPendingReturn $activePendingReturn)
    {
        $assignedUser = $asset->assignedTo;

        $replacementAssets = Asset::with(['model', 'assetstatus', 'location'])
            ->where('assigned_type', User::class)
            ->where('assigned_to', $assignedUser->id)
            ->where('id', '!=', $asset->id)
            ->orderBy('asset_tag')
            ->get();

        if ($activePendingReturn?->replacementAsset && ! $replacementAssets->contains('id', $activePendingReturn->replacement_asset_id)) {
            $replacementAssets->push($activePendingReturn->replacementAsset);
        }

        return $replacementAssets;
    }

    private function guardPendingReturnable(Asset $asset): ?RedirectResponse
    {
        if (! $asset->model) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', trans('admin/hardware/general.model_invalid_fix'));
        }

        if (! $asset->assignedTo instanceof User) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', trans('admin/hardware/message.pending_return.not_assigned_to_user'));
        }

        return null;
    }

    private function buildLogNote(Asset $asset, User $currentUser, ?Asset $replacementAsset, array $validated, bool $isUpdate): string
    {
        $parts = [
            trans($isUpdate ? 'admin/hardware/message.pending_return.update_log_note' : 'admin/hardware/message.pending_return.log_note', [
                'asset_tag' => $asset->asset_tag,
                'user' => $currentUser->getFullNameAttribute(),
                'reason' => $validated['reason'],
            ]),
        ];

        if ($replacementAsset) {
            $parts[] = trans('admin/hardware/message.pending_return.replacement_note', [
                'asset_tag' => '@'.$replacementAsset->asset_tag,
            ]);
        }

        if (! empty($validated['expected_return_date'])) {
            $parts[] = trans('admin/hardware/message.pending_return.expected_date_note', [
                'date' => $validated['expected_return_date'],
            ]);
        }

        if (! empty($validated['note'])) {
            $parts[] = trans('admin/hardware/message.pending_return.user_note', [
                'note' => $validated['note'],
            ]);
        }

        return implode(' ', $parts);
    }

    private function buildCancelLogNote(Asset $asset, AssetPendingReturn $pendingReturn, ?string $cancelReason, ?string $replacementResolutionNote = null): string
    {
        $parts = [
            trans('admin/hardware/message.pending_return.cancel_log_note', [
                'asset_tag' => $asset->asset_tag,
                'user' => $pendingReturn->user?->present()->fullName() ?: '-',
            ]),
        ];

        if ($pendingReturn->reason) {
            $parts[] = trans('admin/hardware/message.pending_return.cancel_original_reason_note', [
                'reason' => $pendingReturn->reason,
            ]);
        }

        if ($pendingReturn->replacementAsset) {
            $parts[] = trans('admin/hardware/message.pending_return.replacement_note', [
                'asset_tag' => '@'.$pendingReturn->replacementAsset->asset_tag,
            ]);
        }

        if ($replacementResolutionNote) {
            $parts[] = $replacementResolutionNote;
        }

        $cancelReason = trim((string) $cancelReason);
        if ($cancelReason !== '') {
            $parts[] = trans('admin/hardware/message.pending_return.cancel_reason_note', [
                'reason' => $cancelReason,
            ]);
        }

        return implode(' ', $parts);
    }

    private function buildReplacementCheckinNote(Asset $oldAsset, Asset $replacementAsset, AssetPendingReturn $pendingReturn, ?string $cancelReason): string
    {
        $parts = [
            trans('admin/hardware/message.pending_return.replacement_checkin_log_note', [
                'new_asset_tag' => $replacementAsset->asset_tag,
                'old_asset_tag' => '@'.$oldAsset->asset_tag,
                'user' => $pendingReturn->user?->present()->fullName() ?: '-',
            ]),
        ];

        if ($pendingReturn->reason) {
            $parts[] = trans('admin/hardware/message.pending_return.cancel_original_reason_note', [
                'reason' => $pendingReturn->reason,
            ]);
        }

        $cancelReason = trim((string) $cancelReason);
        if ($cancelReason !== '') {
            $parts[] = trans('admin/hardware/message.pending_return.cancel_reason_note', [
                'reason' => $cancelReason,
            ]);
        }

        return implode(' ', $parts);
    }
}
