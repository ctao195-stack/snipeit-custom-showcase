<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_tag_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('ownership_type', 20);
            $table->string('device_type', 20);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['ownership_type', 'device_type']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->string('asset_ownership_type', 20)->nullable()->after('asset_tag');
            $table->string('asset_device_type', 20)->nullable()->after('asset_ownership_type');
        });

        $rules = [
            ['rental', 'desktop', config('asset_tag_rules.ownership_types.rental.base_prefix').'-001-'],
            ['rental', 'monitor', config('asset_tag_rules.ownership_types.rental.base_prefix').'-002-'],
            ['rental', 'laptop', config('asset_tag_rules.ownership_types.rental.base_prefix').'-003-'],
            ['owned', 'desktop', config('asset_tag_rules.ownership_types.owned.base_prefix').'-001-'],
            ['owned', 'monitor', config('asset_tag_rules.ownership_types.owned.base_prefix').'-002-'],
            ['owned', 'laptop', config('asset_tag_rules.ownership_types.owned.base_prefix').'-003-'],
        ];

        foreach ($rules as [$ownershipType, $deviceType, $prefix]) {
            $max = DB::table('assets')
                ->where('asset_tag', 'like', $prefix.'%')
                ->pluck('asset_tag')
                ->map(function ($tag) use ($prefix) {
                    $suffix = substr($tag, strlen($prefix));

                    return ctype_digit($suffix) ? (int) $suffix : 0;
                })
                ->max() ?? 0;

            DB::table('asset_tag_sequences')->insert([
                'ownership_type' => $ownershipType,
                'device_type' => $deviceType,
                'next_number' => $max + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['asset_ownership_type', 'asset_device_type']);
        });

        Schema::dropIfExists('asset_tag_sequences');
    }
};
