<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->string('photo_name');
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('file_size');
            $table->string('mime_type');
            $table->enum('photo_type', ['before', 'progress', 'after', 'issue', 'general'])->default('general');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // EXIF data, GPS coordinates, etc.
            $table->timestamp('taken_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'photo_type']);
            $table->index(['uploaded_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_photos');
    }
};
