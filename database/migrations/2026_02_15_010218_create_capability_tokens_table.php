<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capability_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('capability');
            $table->string('scope')->nullable();
            $table->string('issuer')->default('system');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamps();

            $table->index(['capability', 'revoked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_tokens');
    }
};
