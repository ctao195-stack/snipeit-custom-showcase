<?php

namespace App\Http\Controllers\Assets;

use App\Exceptions\CheckoutNotAllowed;
use App\Helpers\Helper;
use App\Http\Controllers\CheckInOutRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetCheckoutRequest;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\AssetPendingReturn;
use App\Models\User;
use App\Services\ActionlogAttachmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use \Illuminate\Contracts\View\View;
use \Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class AssetCheckoutController extends Controller
{
    use CheckInOutRequest;

    /**
     * Returns a view that presents a form to check an asset out to a
     * user.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param int $assetId
     * @since [v1.0]
     * @return \Illuminate\Contracts\View\View
     */
    public function create(Asset $asset) : View | RedirectResponse
    {

        $this->authorize('checkout', $asset);

        if (!$asset->model) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', trans('admin/hardware/general.model_invalid_fix'));
        }

        // Invoke the validation to see if the audit will complete successfully
        $asset->setRules($asset->getRules() + $asset->customFieldValidationRules());

        if ($asset->isInvalid()) {
            return redirect()->route('hardware.edit', $asset)->withErrors($asset->getErrors());
        }


        if ($asset->availableForCheckout()) {
            return view('hardware/checkout', compact('asset'))
                ->with('statusLabel_list', Helper::deployableStatusLabelList())
                ->with('table_name', 'Assets')
                ->with('item', $asset);
        }

        return redirect()->route('hardware.index')
            ->with('error', trans('admin/hardware/message.checkout.not_available'));
    }

    /**
     * Validate and process the form data to check out an asset to a user.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param AssetCheckoutRequest $request
     * @since [v1.0]
     */
    public function store(AssetCheckoutRequest $request, $assetId) : RedirectResponse
    {
        try {
            // Check if the asset exists
            if (! $asset = Asset::find($assetId)) {
                return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.does_not_exist'));
            } elseif (! $asset->availableForCheckout()) {
                return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.checkout.not_available'));
            }
            $this->authorize('checkout', $asset);

            if (!$asset->model) {
                return redirect()->route('hardware.show', $asset)->with('error', trans('admin/hardware/general.model_invalid_fix'));
            }

            $admin = auth()->user();

            $target = $this->determineCheckoutTarget();
            $pendingReturnAsset = $this->pendingReturnAssetFromRequest($request, $asset, $target);

            $asset = $this->updateAssetLocation($asset, $target);

            $checkout_at = date('Y-m-d H:i:s');
            if (($request->filled('checkout_at')) && ($request->get('checkout_at') != date('Y-m-d'))) {
                $checkout_at = $request->get('checkout_at');
            }

            $expected_checkin = '';
            if ($request->filled('expected_checkin')) {
                $expected_checkin = $request->get('expected_checkin');
            }

            if ($request->filled('status_id')) {
                $asset->status_id = $request->get('status_id');
            }


            if(!empty($asset->licenseseats->all())){
                if(request('checkout_to_type') == 'user') {
                    foreach ($asset->licenseseats as $seat){
                        $seat->assigned_to = $target->id;
                        $seat->save();
                    }
                }
            }

            // Add any custom fields that should be included in the checkout
            $asset->customFieldsForCheckinCheckout('display_checkout');

            $settings = \App\Models\Setting::getSettings();

            // We have to check whether $target->company_id is null here since locations don't have a company yet
            if (($settings->full_multiple_companies_support) && ((!is_null($target->company_id)) &&  (!is_null($asset->company_id)))) {
                if ($target->company_id != $asset->company_id){
                    return redirect()->route('hardware.checkout.create', $asset)->with('error', trans('general.error_user_company'));
                }
            }

            session()->put(['redirect_option' => $request->get('redirect_option'), 'checkout_to_type' => $request->get('checkout_to_type')]);

            $attachmentsSaved = true;

            $checkoutSucceeded = DB::transaction(function () use ($asset, $target, $admin, $checkout_at, $expected_checkin, $request, $pendingReturnAsset, &$attachmentsSaved) {
                if (! $asset->checkOut($target, $admin, $checkout_at, $expected_checkin, $request->get('note'), $request->get('name'))) {
                    return false;
                }

                $this->completeActiveCustody($asset, $checkout_at);

                if ($pendingReturnAsset && $target instanceof User) {
                    $this->savePendingReturnFromCheckout($request, $pendingReturnAsset, $target, $asset, $checkout_at);
                }

                $this->syncMentionedAssets($request, $asset);
                $attachmentsSaved = $this->storeActionlogAttachments($request, $asset, 'checkout');

                return true;
            });

            if ($checkoutSucceeded) {
                $successMessage = trans('admin/hardware/message.checkout.success');

                if ($pendingReturnAsset) {
                    $successMessage .= ' '.trans('admin/hardware/message.checkout.pending_return_success');
                }

                if (! $attachmentsSaved) {
                    $successMessage .= ' '.trans('admin/hardware/message.attachments.not_saved');
                }

                return Helper::getRedirectOption($request, $asset->id, 'Assets')
                    ->with('success', $successMessage);
            }
            // Redirect to the asset management page with error
            return redirect()->route("hardware.checkout.create", $asset)->with('error', trans('admin/hardware/message.checkout.error').$asset->getErrors());
        } catch (ModelNotFoundException $e) {
            return redirect()->back()->with('error', trans('admin/hardware/message.checkout.error'))->withErrors($asset->getErrors());
        } catch (CheckoutNotAllowed $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (RuntimeException $e) {
            return redirect()->route('hardware.checkout.create', $assetId)
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('hardware.checkout.create', $assetId)
                ->withInput()
                ->with('error', trans('admin/hardware/message.checkout.error'));
        }
    }

    private function pendingReturnAssetFromRequest(AssetCheckoutRequest $request, Asset $checkoutAsset, $target): ?Asset
    {
        if (! $request->filled('pending_return_asset_id')) {
            return null;
        }

        if (! $target instanceof User) {
            throw new RuntimeException(trans('admin/hardware/message.pending_return.checkout_only_user'));
        }

        $pendingReturnAsset = Asset::findOrFail($request->input('pending_return_asset_id'));

        if ($pendingReturnAsset->id === $checkoutAsset->id) {
            throw new RuntimeException(trans('admin/hardware/message.pending_return.same_asset'));
        }

        if (! $pendingReturnAsset->model) {
            throw new RuntimeException(trans('admin/hardware/general.model_invalid_fix'));
        }

        $this->authorize('update', $pendingReturnAsset);

        if ($pendingReturnAsset->assigned_type !== User::class || (int) $pendingReturnAsset->assigned_to !== (int) $target->id) {
            throw new RuntimeException(trans('admin/hardware/message.pending_return.pending_asset_not_assigned_to_user'));
        }

        return $pendingReturnAsset;
    }

    private function syncMentionedAssets(AssetCheckoutRequest $request, Asset $asset): void
    {
        $mentionedAssetIds = collect($request->input('mentioned_assets', []))
            ->filter()
            ->map(fn ($assetId) => (int) $assetId)
            ->reject(fn ($assetId) => $assetId === $asset->id)
            ->unique()
            ->values();

        if ($mentionedAssetIds->isEmpty()) {
            return;
        }

        $actionlog = Actionlog::where('item_type', Asset::class)
            ->where('item_id', $asset->id)
            ->where('action_type', 'checkout')
            ->where('created_by', auth()->id())
            ->latest('id')
            ->first();

        if ($actionlog) {
            $actionlog->mentionedAssets()->syncWithoutDetaching($mentionedAssetIds->all());
        }
    }

    private function savePendingReturnFromCheckout(AssetCheckoutRequest $request, Asset $pendingReturnAsset, User $currentUser, Asset $replacementAsset, string $checkoutAt): void
    {
        $pendingReturn = AssetPendingReturn::where('asset_id', $pendingReturnAsset->id)
            ->open()
            ->lockForUpdate()
            ->first();

        $isUpdate = (bool) $pendingReturn;

        if (! $pendingReturn) {
            $pendingReturn = new AssetPendingReturn([
                'asset_id' => $pendingReturnAsset->id,
                'created_by' => auth()->id(),
                'status' => 'pending',
            ]);
        }

        $pendingReturn->fill([
            'user_id' => $currentUser->id,
            'replacement_asset_id' => $replacementAsset->id,
            'reason' => $request->input('pending_return_reason'),
            'expected_return_date' => $request->input('pending_return_expected_return_date') ?: null,
            'note' => $request->input('pending_return_note') ?: null,
        ]);

        if (! $pendingReturn->save()) {
            throw new RuntimeException(trans('admin/hardware/message.pending_return.error'));
        }

        $actionlog = new Actionlog();
        $actionlog->item_type = Asset::class;
        $actionlog->item_id = $pendingReturnAsset->id;
        $actionlog->target_type = Asset::class;
        $actionlog->target_id = $replacementAsset->id;
        $actionlog->created_by = auth()->id();
        $actionlog->note = $this->buildPendingReturnLogNote($pendingReturnAsset, $currentUser, $replacementAsset, $request, $isUpdate);
        $actionlog->action_date = $checkoutAt;
        $actionlog->logaction($isUpdate ? 'pending return update' : 'pending return');
        $actionlog->mentionedAssets()->syncWithoutDetaching([$replacementAsset->id]);
    }

    private function buildPendingReturnLogNote(Asset $pendingReturnAsset, User $currentUser, Asset $replacementAsset, AssetCheckoutRequest $request, bool $isUpdate): string
    {
        $parts = [
            trans($isUpdate ? 'admin/hardware/message.pending_return.update_log_note' : 'admin/hardware/message.pending_return.log_note', [
                'asset_tag' => $pendingReturnAsset->asset_tag,
                'user' => $currentUser->getFullNameAttribute(),
                'reason' => $request->input('pending_return_reason'),
            ]),
            trans('admin/hardware/message.pending_return.replacement_note', [
                'asset_tag' => '@'.$replacementAsset->asset_tag,
            ]),
        ];

        if ($request->filled('pending_return_expected_return_date')) {
            $parts[] = trans('admin/hardware/message.pending_return.expected_date_note', [
                'date' => $request->input('pending_return_expected_return_date'),
            ]);
        }

        if ($request->filled('pending_return_note')) {
            $parts[] = trans('admin/hardware/message.pending_return.user_note', [
                'note' => $request->input('pending_return_note'),
            ]);
        }

        return implode(' ', $parts);
    }

    private function completeActiveCustody(Asset $asset, string $checkoutAt): void
    {
        $custody = AssetCustody::where('asset_id', $asset->id)
            ->active()
            ->lockForUpdate()
            ->first();

        if (! $custody) {
            return;
        }

        $custody->fill([
            'status' => AssetCustody::STATUS_COMPLETED,
            'completed_at' => $checkoutAt,
            'completed_by' => auth()->id(),
        ]);

        $custody->save();
    }

    private function storeActionlogAttachments(AssetCheckoutRequest $request, Asset $asset, string $actionType): bool
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
