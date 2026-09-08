<?php

namespace App\Http\Controllers\Assets;

use App\Events\CheckoutableCheckedIn;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\AssetPendingReturn;
use App\Models\CheckoutAcceptance;
use App\Models\LicenseSeat;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssetCustodyController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $this->authorize('index', Asset::class);

        $search = trim((string) $request->input('search', ''));
        $previousUserStatus = $this->validatedPreviousUserStatus($request->input('previous_user_status'));

        $custodies = $this->custodyQuery($search, $previousUserStatus)
            ->active()
            ->orderByDesc('custody_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->appends($request->query());

        return view('hardware.custodies-index', [
            'custodies' => $custodies,
            'search' => $search,
            'previousUserStatus' => $previousUserStatus,
            'previousUserStatusOptions' => AssetCustody::previousUserStatusOptions(),
        ]);
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        $this->authorize('index', Asset::class);

        $search = trim((string) $request->input('search', ''));
        $previousUserStatus = $this->validatedPreviousUserStatus($request->input('previous_user_status'));
        $rows = $this->custodyQuery($search, $previousUserStatus)
            ->orderByDesc('custody_at')
            ->orderByDesc('id')
            ->get();
        $filename = 'asset-custody-ledger-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                '代管状态',
                '资产标签',
                '资产名称',
                '序列号',
                '型号',
                '当前资产状态',
                '当前分配对象',
                '原使用人',
                '原使用人状态',
                '原使用人部门',
                '保管人',
                '保管人部门',
                '代管地点',
                '代管原因',
                '备注',
                '代管日期',
                '完成时间',
                '完成人',
                '创建时间',
                '创建人',
            ]);

            foreach ($rows as $custody) {
                fputcsv($output, $this->exportRow($custody));
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function updatePreviousUserStatus(Request $request, AssetCustody $custody): RedirectResponse
    {
        if ($custody->asset) {
            $this->authorize('update', $custody->asset);
        } else {
            $this->authorize('index', Asset::class);
        }

        $validated = $request->validate([
            'previous_user_status' => ['required', 'string', Rule::in(array_keys(AssetCustody::previousUserStatusOptions()))],
        ]);

        $custody->previous_user_status = $validated['previous_user_status'];
        $custody->save();

        return redirect()->back()
            ->with('success', trans('admin/hardware/message.custody.previous_user_status_updated'));
    }

    public function create(Asset $asset): View|RedirectResponse
    {
        $this->authorize('checkin', $asset);
        $this->authorize('update', $asset);

        if ($redirect = $this->guardCustodiable($asset, 'hardware.show')) {
            return $redirect;
        }

        return view('hardware.custody', [
            'asset' => $asset,
            'item' => $asset,
            'currentUser' => $this->assignedUserForCustody($asset),
            'custodyStatus' => $this->custodyStatus(),
            'previousUserStatusOptions' => AssetCustody::previousUserStatusOptions(),
            'defaultPreviousUserStatus' => $this->defaultPreviousUserStatus($this->assignedUserForCustody($asset)),
        ]);
    }

    public function store(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('checkin', $asset);
        $this->authorize('update', $asset);

        if ($redirect = $this->guardCustodiable($asset, 'hardware.custody.create')) {
            return $redirect;
        }

        $validated = $request->validate([
            'custodian_user_id' => ['required', 'integer', 'exists:users,id'],
            'previous_user_status' => ['required', 'string', Rule::in(array_keys(AssetCustody::previousUserStatusOptions()))],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'custody_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $oldUser = $this->assignedUserForCustody($asset);
        $custodian = User::findOrFail($validated['custodian_user_id']);

        if ((int) $oldUser->id === (int) $custodian->id) {
            return redirect()->route('hardware.custody.create', $asset)
                ->withInput()
                ->with('error', trans('admin/hardware/message.custody.same_user'));
        }

        if (! $this->passesCompanyCheck(Setting::getSettings(), $asset, $custodian)) {
            return redirect()->route('hardware.custody.create', $asset)
                ->withInput()
                ->with('error', trans('general.error_user_company'));
        }

        try {
            DB::transaction(function () use ($asset, $oldUser, $custodian, $validated) {
                $asset = Asset::where('id', $asset->id)->lockForUpdate()->firstOrFail();
                $asset->load('licenseseats');
                $freshOldUser = $this->assignedUserForCustody($asset);

                if (! $freshOldUser || (int) $freshOldUser->id !== (int) $oldUser->id) {
                    throw new RuntimeException(trans('admin/hardware/message.custody.not_assigned_to_user'));
                }

                if (AssetCustody::where('asset_id', $asset->id)->active()->lockForUpdate()->exists()) {
                    throw new RuntimeException(trans('admin/hardware/message.custody.already_active'));
                }

                $custodyStatus = $this->custodyStatus();
                $custodyAt = Carbon::parse($validated['custody_at'].' '.date('H:i:s'))->format('Y-m-d H:i:s');
                $locationId = $validated['location_id'] ?: $asset->location_id;

                AssetCustody::create([
                    'asset_id' => $asset->id,
                    'previous_user_id' => $freshOldUser->id,
                    'previous_user_status' => $validated['previous_user_status'],
                    'custodian_user_id' => $custodian->id,
                    'department_id' => $custodian->department_id ?: $freshOldUser->department_id,
                    'location_id' => $locationId,
                    'created_by' => auth()->id(),
                    'custody_at' => $custodyAt,
                    'reason' => $validated['reason'],
                    'note' => $validated['note'] ?? null,
                    'status' => AssetCustody::STATUS_ACTIVE,
                ]);

                $this->checkInForCustody($asset, $freshOldUser, $custodian, $custodyStatus, $locationId, $custodyAt, $validated);
                $this->completePendingReturn($asset, $custodyAt);
            });
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('hardware.custody.create', $asset)
                ->withInput()
                ->with('error', $exception instanceof RuntimeException ? $exception->getMessage() : trans('admin/hardware/message.custody.error'));
        }

        return redirect()->route('hardware.show', $asset)
            ->with('success', trans('admin/hardware/message.custody.success'));
    }

    private function custodyQuery(string $search, ?string $previousUserStatus = null): Builder
    {
        return AssetCustody::with([
                'asset.model.manufacturer',
                'asset.assetstatus',
                'asset.location',
                'asset.assignedTo',
                'previousUser.department',
                'previousUser.location',
                'custodian.department',
                'custodian.location',
                'department',
                'location',
                'creator',
                'completer',
            ])
            ->when($previousUserStatus, function (Builder $query) use ($previousUserStatus) {
                $query->where('previous_user_status', $previousUserStatus);
            })
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('reason', 'like', '%'.$search.'%')
                        ->orWhere('note', 'like', '%'.$search.'%')
                        ->orWhereHas('asset', function (Builder $assetQuery) use ($search) {
                            $assetQuery->where('asset_tag', 'like', '%'.$search.'%')
                                ->orWhere('serial', 'like', '%'.$search.'%')
                                ->orWhere('name', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('previousUser', function (Builder $userQuery) use ($search) {
                            $userQuery->where('first_name', 'like', '%'.$search.'%')
                                ->orWhere('last_name', 'like', '%'.$search.'%')
                                ->orWhere('username', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('custodian', function (Builder $userQuery) use ($search) {
                            $userQuery->where('first_name', 'like', '%'.$search.'%')
                                ->orWhere('last_name', 'like', '%'.$search.'%')
                                ->orWhere('username', 'like', '%'.$search.'%');
                        });
                });
            });
    }

    private function exportRow(AssetCustody $custody): array
    {
        $asset = $custody->asset;
        $currentAssignee = $asset?->assignedTo;

        return [
            $custody->status === AssetCustody::STATUS_ACTIVE ? '代管中' : '已完成',
            $asset?->asset_tag ?: '-',
            $asset?->name ?: optional($asset?->model)->name ?: '-',
            $asset?->serial ?: '-',
            optional($asset?->model)->name ?: '-',
            optional($asset?->assetstatus)->name ?: '-',
            $currentAssignee ? $this->displayName($currentAssignee) : '-',
            $custody->previousUser ? $custody->previousUser->getFullNameAttribute() : '-',
            $custody->previousUserStatusLabel(),
            optional(optional($custody->previousUser)->department)->name ?: '-',
            $custody->custodian ? $custody->custodian->getFullNameAttribute() : '-',
            optional(optional($custody->custodian)->department)->name ?: '-',
            optional($custody->location)->name ?: '-',
            $custody->reason ?: '-',
            $custody->note ?: '-',
            $this->formatDateTime($custody->custody_at),
            $this->formatDateTime($custody->completed_at),
            $custody->completer ? $custody->completer->getFullNameAttribute() : '-',
            $this->formatDateTime($custody->created_at),
            $custody->creator ? $custody->creator->getFullNameAttribute() : '-',
        ];
    }

    private function displayName($model): string
    {
        if ($model instanceof User) {
            return $model->getFullNameAttribute();
        }

        return method_exists($model, 'present') ? strip_tags($model->present()->name()) : (string) ($model->name ?? '-');
    }

    private function formatDateTime($value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i:s') : '-';
    }

    private function guardCustodiable(Asset $asset, string $route): ?RedirectResponse
    {
        if (! $asset->model) {
            return redirect()->route($route, $asset)
                ->with('error', trans('admin/hardware/general.model_invalid_fix'));
        }

        if (! $this->assignedUserForCustody($asset)) {
            return redirect()->route($route, $asset)
                ->with('error', trans('admin/hardware/message.custody.not_assigned_to_user'));
        }

        if ($asset->activeCustody()->exists()) {
            return redirect()->route($route, $asset)
                ->with('error', trans('admin/hardware/message.custody.already_active'));
        }

        return null;
    }

    private function assignedUserForCustody(Asset $asset): ?User
    {
        if ($asset->assigned_type !== User::class || ! $asset->assigned_to) {
            return null;
        }

        return User::withTrashed()->find($asset->assigned_to);
    }

    private function defaultPreviousUserStatus(?User $user): string
    {
        if (! $user) {
            return AssetCustody::PREVIOUS_USER_STATUS_OTHER;
        }

        if ((method_exists($user, 'trashed') && $user->trashed()) || ! $user->activated) {
            return AssetCustody::PREVIOUS_USER_STATUS_LEFT;
        }

        return AssetCustody::PREVIOUS_USER_STATUS_ACTIVE;
    }

    private function validatedPreviousUserStatus($status): ?string
    {
        $status = is_string($status) ? $status : null;

        return $status !== null && array_key_exists($status, AssetCustody::previousUserStatusOptions()) ? $status : null;
    }

    private function custodyStatus(): Statuslabel
    {
        $status = Statuslabel::where('name', '部门代管')->first();

        if (! $status) {
            $status = new Statuslabel();
            $status->name = '部门代管';
        }

        $status->deployable = 1;
        $status->pending = 0;
        $status->archived = 0;
        $status->notes = $status->notes ?: trans('admin/hardware/message.custody.status_created_note');

        if (! $status->save()) {
            throw new RuntimeException(trans('admin/hardware/message.custody.error'));
        }

        return $status;
    }

    private function passesCompanyCheck(Setting $settings, Asset $asset, User $custodian): bool
    {
        if (! $settings->full_multiple_companies_support) {
            return true;
        }

        if (is_null($asset->company_id) || is_null($custodian->company_id)) {
            return true;
        }

        return (int) $asset->company_id === (int) $custodian->company_id;
    }

    private function checkInForCustody(Asset $asset, User $oldUser, User $custodian, Statuslabel $status, ?int $locationId, string $actionDate, array $validated): void
    {
        $originalValues = $asset->getRawOriginal();

        if ($actionDate && strpos($actionDate, date('Y-m-d')) === false) {
            $originalValues['action_date'] = date('Y-m-d H:i:s');
        }

        $asset->expected_checkin = null;
        $asset->assignedTo()->disassociate();
        $asset->accepted = null;
        $asset->status_id = $status->id;
        $asset->last_checkin = $actionDate;

        if ($locationId) {
            $asset->location_id = $locationId;
        }

        $asset->licenseseats->each(function (LicenseSeat $seat) {
            $seat->update(['assigned_to' => null]);
        });

        CheckoutAcceptance::pending()->whereHasMorph('checkoutable',
            [Asset::class],
            function (Builder $query) use ($asset) {
                $query->where('id', $asset->id);
            })->get()->map(function ($acceptance) {
                $acceptance->delete();
            });

        if (! $asset->save()) {
            throw new RuntimeException(trans('admin/hardware/message.custody.error'));
        }

        event(new CheckoutableCheckedIn(
            $asset,
            $oldUser,
            auth()->user(),
            $this->buildCustodyNote($asset, $oldUser, $custodian, $validated, $locationId),
            $actionDate,
            $originalValues
        ));
    }

    private function completePendingReturn(Asset $asset, string $checkinAt): void
    {
        $actionlog = Actionlog::where('item_type', Asset::class)
            ->where('item_id', $asset->id)
            ->where('action_type', 'checkin from')
            ->where('created_by', auth()->id())
            ->latest('id')
            ->first();

        AssetPendingReturn::where('asset_id', $asset->id)
            ->open()
            ->update([
                'status' => 'completed',
                'completed_at' => $checkinAt,
                'resolved_by' => auth()->id(),
                'completed_actionlog_id' => $actionlog?->id,
            ]);
    }

    private function buildCustodyNote(Asset $asset, User $oldUser, User $custodian, array $validated, ?int $locationId): string
    {
        $parts = [
            trans('admin/hardware/message.custody.log_note', [
                'asset_tag' => $asset->asset_tag,
                'from' => $oldUser->getFullNameAttribute(),
                'custodian' => $custodian->getFullNameAttribute(),
                'reason' => $validated['reason'],
            ]),
        ];

        $locationName = $locationId ? optional(\App\Models\Location::find($locationId))->name : null;
        if ($locationName) {
            $parts[] = trans('admin/hardware/message.custody.location_note', [
                'location' => $locationName,
            ]);
        }

        if (! empty($validated['previous_user_status'])) {
            $parts[] = trans('admin/hardware/message.custody.previous_user_status_note', [
                'status' => AssetCustody::previousUserStatusOptions()[$validated['previous_user_status']]
                    ?? trans('general.previous_user_status_unknown'),
            ]);
        }

        if (! empty($validated['note'])) {
            $parts[] = trans('admin/hardware/message.custody.user_note', [
                'note' => $validated['note'],
            ]);
        }

        return implode(' ', $parts);
    }
}
