<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->string('album_name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('cover_photo_id')->nullable(); // Simple integer field, no constraint
            $table->integer('photos_count')->default(0);
            $table->enum('visibility', ['public', 'private', 'team_only'])->default('team_only');
            $table->timestamps();

            $table->index(['project_id']);
            $table->index(['created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_albums');
    }
};
