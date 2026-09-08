<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetTagSequence extends Model
{
    protected $fillable = [
        'ownership_type',
        'device_type',
        'next_number',
    ];

    protected $casts = [
        'next_number' => 'integer',
    ];
}
