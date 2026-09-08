<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actionlog_asset_mentions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('actionlog_id');
            $table->unsignedInteger('asset_id');
            $table->timestamps();

            $table->unique(['actionlog_id', 'asset_id']);
            $table->foreign('actionlog_id')->references('id')->on('action_logs')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actionlog_asset_mentions');
    }
};
