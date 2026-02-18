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
        Schema::table('token_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->index('agent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('token_usages', function (Blueprint $table) {
            $table->dropIndex(['agent_id']);
            $table->dropColumn('agent_id');
        });
    }
};
