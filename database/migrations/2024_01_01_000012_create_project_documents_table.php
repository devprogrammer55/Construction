<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->string('document_name');
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('file_size');
            $table->string('mime_type');
            $table->enum('document_type', ['plan', 'blueprint', 'specification', 'contract', 'permit', 'report', 'other'])->default('other');
            $table->string('version', 10)->default('1.0');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'review', 'approved', 'archived'])->default('draft');
            $table->json('metadata')->nullable();
            $table->boolean('is_current_version')->default(true);
            $table->foreignId('parent_document_id')->nullable()->constrained('project_documents')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['project_id', 'document_type']);
            $table->index(['uploaded_by']);
            $table->index(['status']);
            $table->index(['is_current_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_documents');
    }
};
