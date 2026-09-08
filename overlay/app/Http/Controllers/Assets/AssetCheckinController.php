<?php

namespace App\Http\Controllers\Assets;

use App\Events\CheckoutableCheckedIn;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetCheckinRequest;
use App\Http\Traits\MigratesLegacyAssetLocations;
use App\Models\Asset;
use App\Models\Actionlog;
use App\Models\AssetPendingReturn;
use App\Models\CheckoutAcceptance;
use App\Models\LicenseSeat;
use App\Services\ActionlogAttachmentService;
use App\Services\AssetMentionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use \Illuminate\Contracts\View\View;
use \Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

class AssetCheckinController extends Controller
{
    use MigratesLegacyAssetLocations;

    public function mentionSearch(Request $request): JsonResponse
    {
        Gate::authorize('view.selectlists');

        $term = trim((string) $request->input('search', ''));

        return response()->json([
            'results' => app(AssetMentionService::class)->searchResults($term),
        ]);
    }

    /**
     * Returns a view that presents a form to check an asset back into inventory.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @param string $backto
     * @since [v1.0]
     */
    public function create(Asset $asset, $backto = null) : View | RedirectResponse
    {

        $this->authorize('checkin', $asset);

        // This asset is already checked in, redirect
        if (is_null($asset->assignedTo)) {
            return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.checkin.already_checked_in'));
        }

        if (!$asset->model) {
            return redirect()->route('hardware.show', $asset->id)->with('error', trans('admin/hardware/general.model_invalid_fix'));
        }

        // Invoke the validation to see if the audit will complete successfully
        $asset->setRules($asset->getRules() + $asset->customFieldValidationRules());

        if ($asset->isInvalid()) {
            return redirect()->route('hardware.edit', $asset)->withErrors($asset->getErrors());
        }

        $target_option = match ($asset->assigned_type) {
            'App\Models\Asset' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.asset_previous')]),
            'App\Models\Location' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.location')]),
            default => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.user')]),
        };
        return view('hardware/checkin', compact('asset', 'target_option'))
            ->with('item', $asset)
            ->with('statusLabel_list', Helper::statusLabelList())
            ->with('backto', $backto)
            ->with('table_name', 'Assets');
    }

    /**
     * Validate and process the form data to check an asset back into inventory.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param AssetCheckinRequest $request
     * @param int $assetId
     * @param null $backto
     * @since [v1.0]
     */
    public function store(AssetCheckinRequest $request, $assetId = null, $backto = null) : RedirectResponse
    {
        // Check if the asset exists
        if (is_null($asset = Asset::find($assetId))) {
            // Redirect to the asset management page with error
            return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.does_not_exist'));
        }

        if (is_null($target = $asset->assignedTo)) {
            return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.checkin.already_checked_in'));
        }

        if (!$asset->model) {
            return redirect()->route('hardware.show', $asset->id)->with('error', trans('admin/hardware/general.model_invalid_fix'));
        }

        $this->authorize('checkin', $asset);

        session()->put('checkedInFrom', $asset->assignedTo->id);
        session()->put('checkout_to_type', match ($asset->assigned_type) {
            'App\Models\User' => 'user',
            'App\Models\Location' => 'location',
            'App\Models\Asset' => 'asset',
        });

        $asset->expected_checkin = null;
        $asset->assignedTo()->disassociate($asset);
        $asset->accepted = null;
        $asset->name = $request->get('name');

        if ($request->filled('status_id')) {
            $asset->status_id = e($request->get('status_id'));
        }

        // Add any custom fields that should be included in the checkout
        $asset->customFieldsForCheckinCheckout('display_checkin');

        $this->migrateLegacyLocations($asset);

        $asset->location_id = $asset->rtd_location_id;

        if ($request->filled('location_id')) {
            Log::debug('NEW Location ID: '.$request->get('location_id'));
            $asset->location_id = $request->get('location_id');

            if ($request->get('update_default_location') == 0){
                $asset->rtd_location_id = $request->get('location_id');
            }
        }

        $originalValues = $asset->getRawOriginal();

        // Handle last checkin date
        $checkin_at = date('Y-m-d H:i:s');
        if (($request->filled('checkin_at')) && ($request->get('checkin_at') != date('Y-m-d'))) {
            $originalValues['action_date'] = $checkin_at;
            $checkin_at = $request->get('checkin_at');

        }
        $asset->last_checkin = $checkin_at;

        $asset->licenseseats->each(function (LicenseSeat $seat) {
            $seat->update(['assigned_to' => null]);
        });

        // Get all pending Acceptances for this asset and delete them
        $acceptances = CheckoutAcceptance::pending()->whereHasMorph('checkoutable',
            [Asset::class],
            function (Builder $query) use ($asset) {
                $query->where('id', $asset->id);
            })->get();
        $acceptances->map(function($acceptance) {
            $acceptance->delete();
        });

        session()->put('redirect_option', $request->get('redirect_option'));

        // Add any custom fields that should be included in the checkout
        $asset->customFieldsForCheckinCheckout('display_checkin');

        if ($asset->save()) {

            event(new CheckoutableCheckedIn($asset, $target, auth()->user(), $request->input('note'), $checkin_at, $originalValues));
            app(AssetMentionService::class)->syncForLatestActionLog($request, $asset, 'checkin from');
            $this->completePendingReturn($asset, $checkin_at);

            $attachmentsSaved = $this->storeActionlogAttachments($request, $asset, 'checkin from');
            $message = trans('admin/hardware/message.checkin.success');

            if (! $attachmentsSaved) {
                $message .= ' '.trans('admin/hardware/message.attachments.not_saved');
            }

            return Helper::getRedirectOption($request, $asset->id, 'Assets')
                ->with('success', $message);
        }
        // Redirect to the asset management page with error
        return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.checkin.error').$asset->getErrors());
    }

    private function completePendingReturn(Asset $asset, string $checkinAt): void
    {
        $actionlog = Actionlog::where('item_type', Asset::class)
            ->where('item_id', $asset->id)
            ->where('action_type', 'checkin from')
            ->where('created_by', auth()->id())
            ->latest('id')
            ->first();

        $pendingReturns = AssetPendingReturn::with(['user', 'replacementAsset.model'])
            ->where('asset_id', $asset->id)
            ->open()
            ->get();

        foreach ($pendingReturns as $pendingReturn) {
            $pendingReturn->fill([
                'status' => 'completed',
                'completed_at' => $checkinAt,
                'resolved_by' => auth()->id(),
                'completed_actionlog_id' => $actionlog?->id,
            ]);
            $pendingReturn->save();

            $this->logPendingReturnCompleted($asset, $pendingReturn, $checkinAt);
        }
    }

    private function logPendingReturnCompleted(Asset $asset, AssetPendingReturn $pendingReturn, string $checkinAt): void
    {
        $replacementAsset = $pendingReturn->replacementAsset;
        $userName = $pendingReturn->user?->present()->fullName() ?: '-';

        $parts = [
            '待归还已完成：'.$asset->asset_tag.' 已由 '.$userName.' 归还。',
        ];

        if ($pendingReturn->reason) {
            $parts[] = '原因：'.$pendingReturn->reason.'。';
        }

        if ($pendingReturn->expected_return_date) {
            $parts[] = '预计归还：'.$pendingReturn->expected_return_date->toDateString().'。';
        }

        $parts[] = '实际归还：'.$checkinAt.'。';

        if ($replacementAsset) {
            $parts[] = '关联新设备：@'.$replacementAsset->asset_tag.'。';
        }

        if ($pendingReturn->note) {
            $parts[] = '备注：'.$pendingReturn->note;
        }

        $actionlog = new Actionlog();
        $actionlog->item_type = Asset::class;
        $actionlog->item_id = $asset->id;
        $actionlog->target_type = $replacementAsset ? Asset::class : null;
        $actionlog->target_id = $replacementAsset?->id;
        $actionlog->created_by = auth()->id();
        $actionlog->note = implode(' ', $parts);
        $actionlog->action_date = $checkinAt;
        $actionlog->logaction('pending return completed');

        if ($replacementAsset) {
            $actionlog->mentionedAssets()->syncWithoutDetaching([$replacementAsset->id]);
        }
    }

    private function storeActionlogAttachments(AssetCheckinRequest $request, Asset $asset, string $actionType): bool
    {
        if (! $request->hasFile('actionlog_attachments')) {
            return true;
        }

        $actionlog = Actionlog::where('item_type', Asset::class)
            ->where('item_id', $asset->id)
            ->where('action_type', $actionType)
            ->where('created_by', auth()->id())
            ->latest('id')
            ->first();

        if (! $actionlog) {
            return false;
        }

        try {
            app(ActionlogAttachmentService::class)->storeFromRequest($request, $actionlog, $asset);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
