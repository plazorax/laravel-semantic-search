<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('semantic-search.stores.pgvector.connection');
    }

    public function up(): void
    {
        $table = (string) config('semantic-search.stores.pgvector.table', 'semantic_search_chunks');
        $dimensions = (int) config('semantic-search.dimensions', 1536);
        $db = DB::connection($this->getConnection());

        // Requires a role allowed to create extensions, or pgvector already enabled.
        $db->statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::connection($this->getConnection())->create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('model_type');
            $blueprint->string('model_id');
            $blueprint->unsignedInteger('chunk_index');
            $blueprint->unsignedInteger('start_offset');
            $blueprint->unsignedInteger('end_offset');
            $blueprint->text('content');
            $blueprint->timestamps();

            $blueprint->index(['model_type', 'model_id']);
        });

        $wrapped = $db->getQueryGrammar()->wrapTable($table);

        $db->statement("ALTER TABLE {$wrapped} ADD COLUMN embedding vector({$dimensions}) NOT NULL");

        // HNSW index for cosine distance. pgvector indexes support up to 2,000 dimensions.
        $db->statement("CREATE INDEX {$table}_embedding_hnsw_idx ON {$wrapped} USING hnsw (embedding vector_cosine_ops)");
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())
            ->dropIfExists((string) config('semantic-search.stores.pgvector.table', 'semantic_search_chunks'));
    }
};
