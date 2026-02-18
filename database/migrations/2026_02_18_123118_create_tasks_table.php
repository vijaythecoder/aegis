<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('assigned_type')->default('user');
            $table->unsignedBigInteger('assigned_id')->nullable();
            $table->string('priority')->default('medium');
            $table->dateTime('deadline')->nullable();
            $table->unsignedBigInteger('parent_task_id')->nullable();
            $table->text('output')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('status');
            $table->index(['assigned_type', 'assigned_id']);
            $table->index('parent_task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
