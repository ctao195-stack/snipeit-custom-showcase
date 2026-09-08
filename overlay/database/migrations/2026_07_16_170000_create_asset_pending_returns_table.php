<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_pending_returns', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('replacement_asset_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->string('reason')->nullable();
            $table->date('expected_return_date')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('completed_actionlog_id')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status', 'completed_at'], 'asset_pending_returns_open_idx');
            $table->index('expected_return_date');

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->foreign('replacement_asset_id')->references('id')->on('assets')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('completed_actionlog_id')->references('id')->on('action_logs')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_pending_returns');
    }
};
