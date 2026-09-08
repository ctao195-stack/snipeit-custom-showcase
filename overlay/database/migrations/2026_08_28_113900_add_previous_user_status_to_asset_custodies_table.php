<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_custodies', function (Blueprint $table) {
            if (! Schema::hasColumn('asset_custodies', 'previous_user_status')) {
                $table->string('previous_user_status', 30)
                    ->nullable()
                    ->after('previous_user_id')
                    ->index('asset_custodies_previous_user_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('asset_custodies', function (Blueprint $table) {
            if (Schema::hasColumn('asset_custodies', 'previous_user_status')) {
                $table->dropIndex('asset_custodies_previous_user_status_idx');
                $table->dropColumn('previous_user_status');
            }
        });
    }
};
