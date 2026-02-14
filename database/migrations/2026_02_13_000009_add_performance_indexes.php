<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages') && ! Schema::hasIndex('messages', 'messages_conversation_created_at_idx')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->index(['conversation_id', 'created_at'], 'messages_conversation_created_at_idx');
            });
        }

        if (Schema::hasTable('audit_logs') && ! Schema::hasIndex('audit_logs', 'audit_logs_conversation_created_at_idx')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['conversation_id', 'created_at'], 'audit_logs_conversation_created_at_idx');
            });
        }

        if (Schema::hasTable('conversations') && ! Schema::hasIndex('conversations', 'conversations_last_message_at_idx')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->index(['last_message_at', 'id'], 'conversations_last_message_at_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('conversations') && Schema::hasIndex('conversations', 'conversations_last_message_at_idx')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropIndex('conversations_last_message_at_idx');
            });
        }

        if (Schema::hasTable('audit_logs') && Schema::hasIndex('audit_logs', 'audit_logs_conversation_created_at_idx')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropIndex('audit_logs_conversation_created_at_idx');
            });
        }

        if (Schema::hasTable('messages') && Schema::hasIndex('messages', 'messages_conversation_created_at_idx')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropIndex('messages_conversation_created_at_idx');
            });
        }
    }
};
