<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_plugins', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('version');
            $table->text('description')->nullable();
            $table->string('author')->nullable();
            $table->unsignedBigInteger('downloads')->default(0);
            $table->decimal('rating', 3, 2)->default(0);
            $table->enum('trust_tier', ['verified', 'community', 'unverified'])->default('unverified');
            $table->string('manifest_url')->nullable();
            $table->string('checksum')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_plugins');
    }
};
