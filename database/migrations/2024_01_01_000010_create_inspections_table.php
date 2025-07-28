<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->constrained('inspection_categories')->onDelete('cascade');
            $table->foreignId('inspector_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('activity_id')->nullable()->constrained('project_activities')->onDelete('set null');
            $table->string('inspection_code')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'failed', 'cancelled'])->default('scheduled');
            $table->enum('result', ['pass', 'fail', 'conditional_pass', 'pending'])->nullable();
            $table->date('scheduled_date');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('weather_conditions')->nullable();
            $table->json('checklist_responses')->nullable();
            $table->text('notes')->nullable();
            $table->text('recommendations')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index(['inspector_id']);
            $table->index(['scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
