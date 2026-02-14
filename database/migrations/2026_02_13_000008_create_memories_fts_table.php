<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->sourceTableExists('memories')) {
            return;
        }

        DB::statement('CREATE VIRTUAL TABLE IF NOT EXISTS memories_fts USING fts5(value, content=memories, content_rowid=id)');

        DB::statement("
            CREATE TRIGGER IF NOT EXISTS memories_fts_insert AFTER INSERT ON memories BEGIN
                INSERT INTO memories_fts(rowid, value) VALUES (new.id, new.value);
            END
        ");

        DB::statement("
            CREATE TRIGGER IF NOT EXISTS memories_fts_update AFTER UPDATE ON memories BEGIN
                INSERT INTO memories_fts(memories_fts, rowid, value) VALUES ('delete', old.id, old.value);
                INSERT INTO memories_fts(rowid, value) VALUES (new.id, new.value);
            END
        ");

        DB::statement("
            CREATE TRIGGER IF NOT EXISTS memories_fts_delete AFTER DELETE ON memories BEGIN
                INSERT INTO memories_fts(memories_fts, rowid, value) VALUES ('delete', old.id, old.value);
            END
        ");

        DB::statement("INSERT INTO memories_fts(memories_fts) VALUES ('rebuild')");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS memories_fts_delete');
        DB::statement('DROP TRIGGER IF EXISTS memories_fts_update');
        DB::statement('DROP TRIGGER IF EXISTS memories_fts_insert');
        DB::statement('DROP TABLE IF EXISTS memories_fts');
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
