<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_name');
            $table->string('reviewer')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('review')->nullable();
            $table->timestamps();

            $table->index('plugin_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_reviews');
    }
};
