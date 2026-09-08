<?php

namespace App\Models;

use App\Helpers\Helper;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ActionlogAttachment extends SnipeModel
{
    use HasFactory;

    protected $fillable = [
        'actionlog_id',
        'asset_id',
        'uploaded_by',
        'filename',
        'original_filename',
        'mime_type',
        'filesize',
        'path',
    ];

    public function actionlog()
    {
        return $this->belongsTo(Actionlog::class, 'actionlog_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isBrowserPreviewableImage(): bool
    {
        return in_array(strtolower(pathinfo($this->filename, PATHINFO_EXTENSION)), [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'bmp',
        ], true);
    }

    public function formattedFilesize(): string
    {
        return Helper::formatFilesizeUnits((int) $this->filesize);
    }
}
