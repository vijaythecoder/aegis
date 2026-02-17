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
        Schema::create('pending_actions', function (Blueprint $table) {
            $table->id();
            $table->string('tool_name');
            $table->json('tool_params');
            $table->text('description');
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('delivery_channel')->default('chat');
            $table->string('resolved_via')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('result')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_actions');
    }
};
