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
        Schema::table('memories', function (Blueprint $table) {
            $table->text('previous_value')->nullable()->after('value');
            $table->timestamp('last_accessed_at')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropColumn(['previous_value', 'last_accessed_at']);
        });
    }
};
