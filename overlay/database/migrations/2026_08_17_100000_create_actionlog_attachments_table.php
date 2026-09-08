<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actionlog_attachments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('actionlog_id');
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('uploaded_by')->nullable();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('filesize')->default(0);
            $table->string('path');
            $table->timestamps();

            $table->index(['actionlog_id', 'asset_id']);
            $table->foreign('actionlog_id')->references('id')->on('action_logs')->onDelete('cascade');
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actionlog_attachments');
    }
};
