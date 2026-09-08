<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_pending_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('asset_pending_returns', 'source')) {
                $table->string('source', 40)->default('manual')->after('status');
                $table->index('source', 'asset_pending_returns_source_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('asset_pending_returns', function (Blueprint $table) {
            if (Schema::hasColumn('asset_pending_returns', 'source')) {
                $table->dropIndex('asset_pending_returns_source_idx');
                $table->dropColumn('source');
            }
        });
    }
};
