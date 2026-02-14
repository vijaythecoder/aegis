<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('tool_name');
            $table->string('scope')->nullable();
            $table->enum('permission', ['allow', 'deny', 'ask']);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_permissions');
    }
};
