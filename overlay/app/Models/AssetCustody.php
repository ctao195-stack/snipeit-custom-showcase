<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetCustody extends SnipeModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const PREVIOUS_USER_STATUS_ACTIVE = 'active';
    public const PREVIOUS_USER_STATUS_LEFT = 'left';
    public const PREVIOUS_USER_STATUS_OTHER = 'other';

    protected $table = 'asset_custodies';

    protected $fillable = [
        'asset_id',
        'previous_user_id',
        'previous_user_status',
        'custodian_user_id',
        'department_id',
        'location_id',
        'created_by',
        'completed_by',
        'custody_at',
        'reason',
        'note',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'custody_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('completed_at')
            ->where('status', self::STATUS_ACTIVE);
    }

    public static function previousUserStatusOptions(): array
    {
        return [
            self::PREVIOUS_USER_STATUS_ACTIVE => trans('general.previous_user_status_active'),
            self::PREVIOUS_USER_STATUS_LEFT => trans('general.previous_user_status_left'),
            self::PREVIOUS_USER_STATUS_OTHER => trans('general.previous_user_status_other'),
        ];
    }

    public function previousUserStatusLabel(): string
    {
        return self::previousUserStatusOptions()[$this->previous_user_status]
            ?? trans('general.previous_user_status_unknown');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function previousUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_user_id')->withTrashed();
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }
}
