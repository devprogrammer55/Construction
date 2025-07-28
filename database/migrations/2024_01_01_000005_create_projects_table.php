<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->string('project_name');
            $table->string('project_code')->unique();
            $table->text('description')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_contact')->nullable();
            $table->string('client_email')->nullable();
            $table->text('project_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code')->nullable();
            $table->decimal('budget', 15, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->enum('status', ['planning', 'ongoing', 'completed', 'on_hold', 'cancelled'])->default('planning');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->json('project_settings')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
