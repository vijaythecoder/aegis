<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->sourceTableExists('messages')) {
            return;
        }

        DB::statement('CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(content, content=messages, content_rowid=id)');

        DB::statement("
            CREATE TRIGGER IF NOT EXISTS messages_fts_insert AFTER INSERT ON messages BEGIN
                INSERT INTO messages_fts(rowid, content) VALUES (new.id, new.content);
            END
        ");

        DB::statement("
            CREATE TRIGGER IF NOT EXISTS messages_fts_update AFTER UPDATE ON messages BEGIN
                INSERT INTO messages_fts(messages_fts, rowid, content) VALUES ('delete', old.id, old.content);
                INSERT INTO messages_fts(rowid, content) VALUES (new.id, new.content);
            END
        ");

        DB::statement("
            CREATE TRIGGER IF NOT EXISTS messages_fts_delete AFTER DELETE ON messages BEGIN
                INSERT INTO messages_fts(messages_fts, rowid, content) VALUES ('delete', old.id, old.content);
            END
        ");

        DB::statement("INSERT INTO messages_fts(messages_fts) VALUES ('rebuild')");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS messages_fts_delete');
        DB::statement('DROP TRIGGER IF EXISTS messages_fts_update');
        DB::statement('DROP TRIGGER IF EXISTS messages_fts_insert');
        DB::statement('DROP TABLE IF EXISTS messages_fts');
    }

    private function sourceTableExists(string $table): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::selectOne(
                'SELECT name FROM sqlite_master WHERE type = ? AND name = ?',
                ['table', $table],
            ) !== null;
        }

        return Schema::hasTable($table);
    }
};
