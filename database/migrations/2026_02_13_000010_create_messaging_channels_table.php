<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_channels', function (Blueprint $table) {
            $table->id();
            $table->string('platform');
            $table->string('platform_channel_id');
            $table->string('platform_user_id');
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->json('config')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['platform', 'platform_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_channels');
    }
};
