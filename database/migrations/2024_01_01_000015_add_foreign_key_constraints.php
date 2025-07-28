<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add foreign key constraints after all tables are created
        Schema::table('project_photos', function (Blueprint $table) {
            $table->foreign('album_id')->references('id')->on('photo_albums')->onDelete('set null');
        });

        Schema::table('photo_albums', function (Blueprint $table) {
            $table->foreign('cover_photo_id')->references('id')->on('project_photos')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('project_photos', function (Blueprint $table) {
            $table->dropForeign(['album_id']);
        });

        Schema::table('photo_albums', function (Blueprint $table) {
            $table->dropForeign(['cover_photo_id']);
        });
    }
};
