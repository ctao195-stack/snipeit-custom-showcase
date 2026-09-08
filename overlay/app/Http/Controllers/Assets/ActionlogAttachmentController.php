<?php

namespace App\Http\Controllers\Assets;

use App\Helpers\StorageHelper;
use App\Http\Controllers\Controller;
use App\Models\ActionlogAttachment;
use App\Models\Asset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActionlogAttachmentController extends Controller
{
    public function show(Asset $asset, ActionlogAttachment $attachment): Response | RedirectResponse | StreamedResponse | BinaryFileResponse
    {
        $this->authorize('view', $asset);

        if ((int) $attachment->asset_id !== (int) $asset->id) {
            abort(404);
        }

        try {
            return StorageHelper::showOrDownloadFile($attachment->path, $attachment->original_filename);
        } catch (\Exception $e) {
            return redirect()->route('hardware.show', $asset)->with('error', trans('general.file_not_found'));
        }
    }
}
