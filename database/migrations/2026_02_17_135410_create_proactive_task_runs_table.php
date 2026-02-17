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
        Schema::create('proactive_task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proactive_task_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('success');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('response_summary')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->decimal('estimated_cost', 12, 8)->default(0);
            $table->text('error_message')->nullable();
            $table->string('delivery_status')->default('pending');
            $table->timestamps();

            $table->index(['proactive_task_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proactive_task_runs');
    }
};
