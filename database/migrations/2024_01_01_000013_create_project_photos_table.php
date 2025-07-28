<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->unsignedBigInteger('album_id')->nullable(); // Simple integer field, no constraint
            $table->string('photo_name');
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('thumbnail_path')->nullable();
            $table->string('file_size');
            $table->string('mime_type');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // EXIF data, GPS coordinates, etc.
            $table->date('photo_date');
            $table->timestamp('taken_at')->nullable();
            $table->enum('visibility', ['public', 'private', 'team_only'])->default('team_only');
            $table->boolean('is_featured')->default(false);
            $table->integer('likes_count')->default(0);
            $table->integer('comments_count')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'photo_date']);
            $table->index(['album_id']);
            $table->index(['uploaded_by']);
            $table->index(['is_featured']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_photos');
    }
};
