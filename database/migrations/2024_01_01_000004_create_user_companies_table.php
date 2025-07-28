<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->enum('role', ['owner', 'admin', 'manager', 'supervisor', 'worker', 'viewer'])->default('worker');
            $table->boolean('is_primary')->default(false);
            $table->enum('status', ['active', 'inactive', 'pending'])->default('pending');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->string('invitation_token')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'company_id']);
            $table->index(['company_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_companies');
    }
};
