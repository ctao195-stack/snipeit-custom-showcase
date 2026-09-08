<?php

namespace App\Http\Controllers\Assets;

use App\Events\CheckoutableCheckedIn;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\LicenseSeat;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class AssetTransferController extends Controller
{
    public function create(Asset $asset): View|RedirectResponse
    {
        $this->authorize('checkin', $asset);
        $this->authorize('checkout', $asset);

        if (! $asset->model) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', trans('admin/hardware/general.model_invalid_fix'));
        }

        if (! $asset->assignedTo instanceof User) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', trans('admin/hardware/message.transfer.not_assigned_to_user'));
        }

        return view('hardware.transfer', compact('asset'))
            ->with('statusLabel_list', Helper::deployableStatusLabelList())
            ->with('item', $asset);
    }

    public function store(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('checkin', $asset);
        $this->authorize('checkout', $asset);

        if (! $asset->assignedTo instanceof User) {
            return redirect()->route('hardware.show', $asset)
                ->with('error', trans('admin/hardware/message.transfer.not_assigned_to_user'));
        }

        $validated = $request->validate([
            'transfer_mode' => ['required', Rule::in(['direct', 'swap'])],
            'transfer_to' => ['required', 'integer', 'exists:users,id'],
            'swap_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'status_id' => [
                'nullable',
                'integer',
                Rule::exists('status_labels', 'id')->where(function ($query) {
                    $query->where('deployable', 1)->where('archived', 0);
                }),
            ],
            'sync_location' => ['nullable', 'boolean'],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $oldUser = $asset->assignedTo;
        $newUser = User::findOrFail($validated['transfer_to']);
        $swapAsset = null;
        $isSwap = $validated['transfer_mode'] === 'swap';

        if ($oldUser->id === $newUser->id) {
            return redirect()->route('hardware.transfer.create', $asset)
                ->withInput()
                ->with('error', trans('admin/hardware/message.transfer.same_user'));
        }

        if ($isSwap) {
            if (empty($validated['swap_asset_id'])) {
                return redirect()->route('hardware.transfer.create', $asset)
                    ->withInput()
                    ->with('error', trans('admin/hardware/message.transfer.swap_asset_required'));
            }

            $swapAsset = Asset::findOrFail($validated['swap_asset_id']);

            if ($asset->id === $swapAsset->id) {
                return redirect()->route('hardware.transfer.create', $asset)
                    ->withInput()
                    ->with('error', trans('admin/hardware/message.transfer.same_asset'));
            }

            if (! $swapAsset->model) {
                return redirect()->route('hardware.transfer.create', $asset)
                    ->withInput()
                    ->with('error', trans('admin/hardware/general.model_invalid_fix'));
            }

            $this->authorize('checkin', $swapAsset);
            $this->authorize('checkout', $swapAsset);

            if (! $swapAsset->assignedTo instanceof User || $swapAsset->assignedTo->id !== $newUser->id) {
                return redirect()->route('hardware.transfer.create', $asset)
                    ->withInput()
                    ->with('error', trans('admin/hardware/message.transfer.swap_asset_not_assigned_to_receiver'));
            }
        }

        $settings = Setting::getSettings();
        if (! $this->passesCompanyCheck($settings, $asset, $newUser)
            || ($isSwap && ! $this->passesCompanyCheck($settings, $swapAsset, $oldUser))) {
            return redirect()->route('hardware.transfer.create', $asset)
                ->withInput()
                ->with('error', trans('general.error_user_company'));
        }

        try {
            DB::transaction(function () use ($asset, $swapAsset, $oldUser, $newUser, $validated, $isSwap) {
                $admin = auth()->user();
                $actionDate = date('Y-m-d H:i:s');
                $syncLocation = ! empty($validated['sync_location']);
                $statusId = $validated['status_id'] ?? null;
                $assetOriginalLocationId = $asset->location_id;
                $swapOriginalLocationId = $swapAsset?->location_id;

                if ($isSwap) {
                    $this->performSwapTransfer($asset, $swapAsset, $oldUser, $newUser, $admin, $actionDate, $statusId, $syncLocation, $assetOriginalLocationId, $swapOriginalLocationId, $validated['note']);
                } else {
                    $this->performDirectTransfer($asset, $oldUser, $newUser, $admin, $actionDate, $statusId, $syncLocation, $assetOriginalLocationId, $validated['note']);
                }
            });
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('hardware.transfer.create', $asset)
                ->withInput()
                ->with('error', trans('admin/hardware/message.transfer.error'));
        }

        return redirect()->route('hardware.show', $asset)
            ->with('success', trans('admin/hardware/message.transfer.success'));
    }

    private function performDirectTransfer(Asset $asset, User $oldUser, User $newUser, User $admin, string $actionDate, ?int $statusId, bool $syncLocation, ?int $originalLocationId, string $reason): void
    {
        $checkinNote = trans('admin/hardware/message.transfer.direct_checkin_note', [
            'from' => $oldUser->getFullNameAttribute(),
            'to' => $newUser->getFullNameAttribute(),
            'asset' => $asset->asset_tag,
            'reason' => $reason,
        ]);

        $checkoutNote = trans('admin/hardware/message.transfer.direct_checkout_note', [
            'from' => $oldUser->getFullNameAttribute(),
            'to' => $newUser->getFullNameAttribute(),
            'asset' => $asset->asset_tag,
            'reason' => $reason,
        ]);

        $this->checkInForTransfer($asset, $oldUser, $checkinNote, $actionDate, $statusId);
        $checkoutLocation = $syncLocation ? null : $originalLocationId;
        $this->checkOutForTransfer($asset, $newUser, $admin, $actionDate, $checkoutNote, $checkoutLocation);
    }

    private function performSwapTransfer(Asset $asset, Asset $swapAsset, User $oldUser, User $newUser, User $admin, string $actionDate, ?int $statusId, bool $syncLocation, ?int $assetOriginalLocationId, ?int $swapOriginalLocationId, string $reason): void
    {
        $assetTag = $asset->asset_tag;
        $swapAssetTag = $swapAsset->asset_tag;

        $assetCheckinNote = trans('admin/hardware/message.transfer.swap_checkin_note', [
            'from' => $oldUser->getFullNameAttribute(),
            'to' => $newUser->getFullNameAttribute(),
            'asset' => $assetTag,
            'swap_asset' => $swapAssetTag,
            'reason' => $reason,
        ]);

        $swapCheckinNote = trans('admin/hardware/message.transfer.swap_checkin_note', [
            'from' => $newUser->getFullNameAttribute(),
            'to' => $oldUser->getFullNameAttribute(),
            'asset' => $swapAssetTag,
            'swap_asset' => $assetTag,
            'reason' => $reason,
        ]);

        $assetCheckoutNote = trans('admin/hardware/message.transfer.swap_checkout_note', [
            'from' => $oldUser->getFullNameAttribute(),
            'to' => $newUser->getFullNameAttribute(),
            'asset' => $assetTag,
            'swap_asset' => $swapAssetTag,
            'reason' => $reason,
        ]);

        $swapCheckoutNote = trans('admin/hardware/message.transfer.swap_checkout_note', [
            'from' => $newUser->getFullNameAttribute(),
            'to' => $oldUser->getFullNameAttribute(),
            'asset' => $swapAssetTag,
            'swap_asset' => $assetTag,
            'reason' => $reason,
        ]);

        $this->checkInForTransfer($asset, $oldUser, $assetCheckinNote, $actionDate, $statusId);
        $this->checkInForTransfer($swapAsset, $newUser, $swapCheckinNote, $actionDate, $statusId);

        $assetCheckoutLocation = $syncLocation ? null : $assetOriginalLocationId;
        $swapCheckoutLocation = $syncLocation ? null : $swapOriginalLocationId;

        $this->checkOutForTransfer($asset, $newUser, $admin, $actionDate, $assetCheckoutNote, $assetCheckoutLocation);
        $this->checkOutForTransfer($swapAsset, $oldUser, $admin, $actionDate, $swapCheckoutNote, $swapCheckoutLocation);
    }

    private function passesCompanyCheck(Setting $settings, Asset $asset, User $user): bool
    {
        if (! $settings->full_multiple_companies_support) {
            return true;
        }

        if (is_null($asset->company_id) || is_null($user->company_id)) {
            return true;
        }

        return $asset->company_id === $user->company_id;
    }

    private function checkInForTransfer(Asset $asset, User $checkedOutTo, string $note, string $actionDate, ?int $statusId): void
    {
        $originalValues = $asset->getRawOriginal();

        $asset->expected_checkin = null;
        $asset->assignedTo()->disassociate();
        $asset->accepted = null;

        if ($statusId) {
            $asset->status_id = $statusId;
        }

        $asset->last_checkin = $actionDate;

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
            throw new \RuntimeException(trans('admin/hardware/message.transfer.error'));
        }

        event(new CheckoutableCheckedIn($asset, $checkedOutTo, auth()->user(), $note, $actionDate, $originalValues));
    }

    private function checkOutForTransfer(Asset $asset, User $target, User $admin, string $actionDate, string $note, ?int $locationId): void
    {
        $asset->licenseseats->each(function (LicenseSeat $seat) use ($target) {
            $seat->update(['assigned_to' => $target->id]);
        });

        if (! $asset->checkOut($target, $admin, $actionDate, null, $note, $asset->name, $locationId)) {
            throw new \RuntimeException(trans('admin/hardware/message.transfer.error'));
        }
    }
}
