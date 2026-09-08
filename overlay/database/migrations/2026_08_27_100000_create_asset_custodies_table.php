<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_custodies', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('previous_user_id')->nullable();
            $table->unsignedInteger('custodian_user_id');
            $table->unsignedInteger('department_id')->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('completed_by')->nullable();
            $table->dateTime('custody_at')->nullable();
            $table->string('reason', 191)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 30)->default('active');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status', 'completed_at'], 'asset_custodies_active_idx');
            $table->index('custodian_user_id', 'asset_custodies_custodian_idx');
            $table->index('department_id', 'asset_custodies_department_idx');

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->foreign('previous_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('custodian_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('completed_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_custodies');
    }
};
