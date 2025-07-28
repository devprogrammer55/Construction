<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('activity_name');
            $table->text('description')->nullable();
            $table->integer('sequence_order')->default(0);
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->enum('status', ['not_started', 'in_progress', 'completed', 'on_hold', 'cancelled'])->default('not_started');
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->decimal('budget_allocated', 12, 2)->nullable();
            $table->decimal('actual_cost', 12, 2)->nullable();
            $table->json('activity_metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'sequence_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activities');
    }
};
