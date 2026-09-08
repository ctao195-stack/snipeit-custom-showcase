<?php

namespace App\Http\Controllers\Assets;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Statuslabel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AssetRetireController extends Controller
{
    public function create(Asset $asset): View|RedirectResponse
    {
        $this->authorize('update', $asset);

        if ($redirect = $this->guardRetirable($asset)) {
            return $redirect;
        }

        return view('hardware.retire', [
            'asset' => $asset,
            'retireStatus' => $this->retireStatus(),
        ])->with('item', $asset);
    }

    public function store(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);

        if ($redirect = $this->guardRetirable($asset)) {
            return $redirect;
        }

        $validated = $request->validate([
            'retire_at' => ['required', 'date'],
            'retire_reason' => ['nullable', 'string', 'max:191'],
            'tracking_number' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $retireStatus = $this->retireStatus();
        $oldStatusId = $asset->status_id;
        $oldStatusName = $asset->assetstatus?->name ?: '-';
        $note = $this->buildRetireNote($asset, $oldStatusName, $retireStatus->name, $validated);

        try {
            DB::transaction(function () use ($asset, $retireStatus, $oldStatusId, $note, $validated) {
                $asset->status_id = $retireStatus->id;
                $asset->expected_checkin = null;
                $asset->accepted = null;

                if (! $asset->save()) {
                    throw new RuntimeException(trans('admin/hardware/message.retire.error'));
                }

                $log = new Actionlog();
                $log->item_type = Asset::class;
                $log->item_id = $asset->id;
                $log->created_by = auth()->id();
                $log->note = $note;
                $log->action_date = $validated['retire_at'];
                $log->log_meta = json_encode([
                    'status_id' => [
                        'old' => $oldStatusId,
                        'new' => $retireStatus->id,
                    ],
                ]);
                $log->logaction('retire');
            });
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->back()
                ->with('error', trans('admin/hardware/message.retire.error'));
        }

        return Helper::getRedirectOption($request, $asset->id, 'Assets')
            ->with('success', trans('admin/hardware/message.retire.success'));
    }

    private function guardRetirable(Asset $asset): ?RedirectResponse
    {
        $retireStatus = $this->retireStatus();

        if (! $retireStatus) {
            return redirect()->back()
                ->with('error', trans('admin/hardware/message.retire.status_missing'));
        }

        if ($asset->assignedTo) {
            return redirect()->back()
                ->with('error', trans('admin/hardware/message.retire.must_checkin_first'));
        }

        if ($asset->status_id === $retireStatus->id) {
            return redirect()->back()
                ->with('warning', trans('admin/hardware/message.retire.already_retired'));
        }

        return null;
    }

    private function retireStatus(): ?Statuslabel
    {
        return Statuslabel::where('name', '退租')->first();
    }

    private function buildRetireNote(Asset $asset, string $oldStatusName, string $newStatusName, array $validated): string
    {
        $parts = [
            trans('admin/hardware/message.retire.log_note', [
                'asset_tag' => $asset->asset_tag,
                'from' => $oldStatusName,
                'to' => $newStatusName,
            ]),
        ];

        if (! empty($validated['retire_reason'])) {
            $parts[] = trans('admin/hardware/message.retire.reason_note', [
                'reason' => $validated['retire_reason'],
            ]);
        }

        if (! empty($validated['tracking_number'])) {
            $parts[] = trans('admin/hardware/message.retire.tracking_note', [
                'tracking_number' => $validated['tracking_number'],
            ]);
        }

        if (! empty($validated['note'])) {
            $parts[] = trans('admin/hardware/message.retire.user_note', [
                'note' => $validated['note'],
            ]);
        }

        return implode(' ', $parts);
    }
}
