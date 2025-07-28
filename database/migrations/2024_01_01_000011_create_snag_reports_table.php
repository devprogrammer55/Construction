<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snag_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('reported_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('activity_id')->nullable()->constrained('project_activities')->onDelete('set null');
            $table->string('snag_code')->unique();
            $table->string('title');
            $table->text('description');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['reported', 'acknowledged', 'in_progress', 'resolved', 'verified', 'closed', 'rejected'])->default('reported');
            $table->enum('category', ['structural', 'electrical', 'plumbing', 'hvac', 'finishing', 'safety', 'other'])->default('other');
            $table->text('location_description')->nullable();
            $table->json('location_coordinates')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->decimal('cost_impact', 12, 2)->nullable();
            $table->integer('time_impact_hours')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['reported_by']);
            $table->index(['assigned_to', 'status']);
            $table->index(['severity', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snag_reports');
    }
};
