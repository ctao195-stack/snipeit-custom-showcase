<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetPendingReturn extends SnipeModel
{
    protected $table = 'asset_pending_returns';

    protected $fillable = [
        'asset_id',
        'replacement_asset_id',
        'user_id',
        'created_by',
        'resolved_by',
        'reason',
        'expected_return_date',
        'note',
        'status',
        'source',
        'completed_at',
        'completed_actionlog_id',
    ];

    protected $casts = [
        'expected_return_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at')->where('status', 'pending');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function replacementAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'replacement_asset_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }

    public function completedActionlog(): BelongsTo
    {
        return $this->belongsTo(Actionlog::class, 'completed_actionlog_id');
    }
}
