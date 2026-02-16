<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vector_embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 50)->index();
            $table->unsignedBigInteger('source_id')->index();
            $table->text('content_preview');
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->binary('embedding');
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vector_embeddings');
    }
};
