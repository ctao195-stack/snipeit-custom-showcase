<?php

namespace App\Services;

use App\Models\Actionlog;
use App\Models\ActionlogAttachment;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ActionlogAttachmentService
{
    private const STORAGE_DIR = 'private_uploads/actionlog_attachments';

    public function storeFromRequest(Request $request, Actionlog $actionlog, Asset $asset): void
    {
        if (! $request->hasFile('actionlog_attachments')) {
            return;
        }

        if (! Storage::exists(self::STORAGE_DIR)) {
            Storage::makeDirectory(self::STORAGE_DIR, 775);
        }

        foreach ((array) $request->file('actionlog_attachments') as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
            $filename = 'actionlog-'.$actionlog->id.'-asset-'.$asset->id.'-'.Str::random(12).'.'.$extension;
            $path = self::STORAGE_DIR.'/'.$filename;

            Storage::putFileAs(self::STORAGE_DIR, $file, $filename);

            ActionlogAttachment::create([
                'actionlog_id' => $actionlog->id,
                'asset_id' => $asset->id,
                'uploaded_by' => auth()->id(),
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName() ?: $filename,
                'mime_type' => $file->getMimeType(),
                'filesize' => $file->getSize() ?: 0,
                'path' => $path,
            ]);
        }
    }
}
