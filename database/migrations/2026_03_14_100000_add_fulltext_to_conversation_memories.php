<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversation_memories', function (Blueprint $table) {
            $table->integer('version')->default(1)->after('tags');
            $table->text('previous_content')->nullable()->after('version');
        });

        // Add FULLTEXT index (PostgreSQL uses GIN with tsvector)
        if (config('database.default') === 'pgsql') {
            DB::statement("CREATE INDEX conversation_memories_content_fulltext ON conversation_memories USING gin(to_tsvector('simple', content))");
        }
    }

    public function down(): void
    {
        Schema::table('conversation_memories', function (Blueprint $table) {
            $table->dropColumn(['version', 'previous_content']);
        });

        if (config('database.default') === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS conversation_memories_content_fulltext');
        }
    }
};
