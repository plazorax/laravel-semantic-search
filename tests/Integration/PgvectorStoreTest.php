<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Plazorax\SemanticSearch\Chunking\TextChunker;
use Plazorax\SemanticSearch\Contracts\Embedder;
use Plazorax\SemanticSearch\Contracts\VectorStore;
use Plazorax\SemanticSearch\Facades\SemanticSearch;
use Plazorax\SemanticSearch\Indexer;
use Plazorax\SemanticSearch\Search\ChunkMatch;
use Plazorax\SemanticSearch\Search\SearchResult;
use Plazorax\SemanticSearch\Stores\InMemoryStore;
use Plazorax\SemanticSearch\Stores\PgvectorStore;
use Plazorax\SemanticSearch\Tests\Fixtures\Article;
use Plazorax\SemanticSearch\Tests\TestCase;

/*
 * Runs against a real PostgreSQL + pgvector database. Skipped unless
 * DB_CONNECTION=pgsql (CI provides a pgvector/pgvector:pg16 service).
 */

beforeEach(function () {
    if (! TestCase::usesPostgres()) {
        $this->markTestSkipped('Requires PostgreSQL with pgvector (set DB_CONNECTION=pgsql).');
    }

    $this->store = new PgvectorStore(DB::connection(), 'semantic_search_chunks', 4);
    $this->app->instance(VectorStore::class, $this->store);

    $this->articles = collect([
        ['Shipping times', 'Domestic orders ship within two business days. International shipping takes seven to fourteen days.'],
        ['Returns and refunds', 'Return unused items within thirty days. Refunds go to the original payment method.'],
        ['Account security', 'Reset a forgotten password from the login page and enable two-factor authentication.'],
    ])->map(fn (array $row) => Article::create(['title' => $row[0], 'body' => $row[1]]));

    $this->articles->each(fn (Article $article) => SemanticSearch::index($article));
});

function chunkRows(?Article $article = null): int
{
    $query = DB::table('semantic_search_chunks')->where('model_type', (new Article)->getMorphClass());

    if ($article !== null) {
        $query->where('model_id', (string) $article->getKey());
    }

    return $query->count();
}

it('creates the vector column and HNSW index', function () {
    $type = DB::selectOne(
        "SELECT format_type(atttypid, atttypmod) AS type FROM pg_attribute WHERE attrelid = 'semantic_search_chunks'::regclass AND attname = 'embedding'"
    );
    $index = DB::selectOne(
        "SELECT indexdef FROM pg_indexes WHERE tablename = 'semantic_search_chunks' AND indexname = 'semantic_search_chunks_embedding_hnsw_idx'"
    );

    expect($type->type)->toBe('vector(64)')
        ->and($index->indexdef)->toContain('hnsw')->toContain('vector_cosine_ops');
});

it('stores chunks with offsets and finds the closest model', function () {
    expect(chunkRows())->toBe(3);

    $top = SemanticSearch::for(Article::class)->query('international shipping days')->get()->first();
    $source = $top->model->toSearchableText();

    expect($top)->toBeInstanceOf(SearchResult::class)
        ->and($top->model->title)->toBe('Shipping times')
        ->and($top->score)->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0)
        ->and(mb_substr($source, $top->citation->start, $top->citation->length()))->toBe($top->citation->text);
});

it('ranks and scores the same way as the in-memory store', function () {
    $memory = new InMemoryStore;
    $indexer = new Indexer(app(Embedder::class), $memory, app(TextChunker::class));
    $this->articles->each(fn (Article $article) => $indexer->index($article));

    [$vector] = app(Embedder::class)->embed(['reset password login']);
    $type = (new Article)->getMorphClass();

    $fromPg = $this->store->search($type, $vector, 3);
    $fromMemory = $memory->search($type, $vector, 3);

    expect(array_map(fn (ChunkMatch $m) => $m->modelId, $fromPg))
        ->toBe(array_map(fn (ChunkMatch $m) => $m->modelId, $fromMemory));

    foreach ($fromPg as $i => $match) {
        // pgvector stores single-precision floats.
        expect($match->score)->toEqualWithDelta($fromMemory[$i]->score, 1e-5);
    }
});

it('returns at most one result per model', function () {
    $long = Article::create(['title' => 'Shipping guide', 'body' => str_repeat('International shipping options and delivery days. ', 15)]);
    expect(SemanticSearch::index($long))->toBeGreaterThan(3);

    $ids = SemanticSearch::for(Article::class)->query('international shipping')->limit(3)->get()
        ->map(fn (SearchResult $r) => $r->model->getKey());

    expect($ids->unique()->count())->toBe($ids->count())
        ->and($ids)->toContain($long->getKey());
});

it('applies limit and minimum score', function () {
    $search = fn () => SemanticSearch::for(Article::class)->query('refunds payment');

    expect($search()->limit(1)->get())->toHaveCount(1)
        ->and($search()->minScore(1.0)->get())->toBeEmpty()
        ->and($search()->minScore(-1.0)->get())->toHaveCount(3);
});

it('replaces, deletes and flushes rows', function () {
    $first = $this->articles->first();
    $first->update(['body' => str_repeat('Lots of words about parcels and couriers. ', 10)]);

    $stored = SemanticSearch::index($first);
    expect(chunkRows($first))->toBe($stored)->toBeGreaterThan(1);

    SemanticSearch::remove($first);
    expect(chunkRows($first))->toBe(0)->and(chunkRows())->toBe(2);

    SemanticSearch::flush(Article::class);
    expect(chunkRows())->toBe(0);
});

it('returns the full limit when the HNSW index filters by model type', function () {
    // 2,000 chunks, of which 10% belong to Article and the rest to another
    // model type. The HNSW index applies the model_type filter after the
    // scan, so with pgvector's default hnsw.ef_search of 40 only about 4
    // candidates would survive it. Large tables get this plan on their own;
    // here sorting is disabled for the test transaction to force it.
    $type = (new Article)->getMorphClass();

    DB::statement(<<<'SQL'
        INSERT INTO semantic_search_chunks
            (model_type, model_id, chunk_index, start_offset, end_offset, content, embedding)
        SELECT CASE WHEN g % 10 = 0 THEN ? ELSE 'other' END, (1000 + g)::text, 0, 0, 1, 'x',
               ARRAY(SELECT random() - 0.5 FROM generate_series(1, 64) WHERE g > 0)::vector(64)
        FROM generate_series(1, 2000) AS g
        SQL, [$type]);
    DB::statement('ANALYZE semantic_search_chunks');
    DB::statement('SET LOCAL enable_sort = off');

    $plan = collect(DB::select(
        'EXPLAIN SELECT id FROM semantic_search_chunks WHERE model_type = ? ORDER BY embedding <=> CAST(? AS vector) LIMIT 40',
        [$type, PgvectorStore::literal(array_fill(0, 64, 0.1))],
    ))->pluck('QUERY PLAN')->implode("\n");
    expect($plan)->toContain('semantic_search_chunks_embedding_hnsw_idx');

    [$vector] = app(Embedder::class)->embed(['international shipping']);
    $matches = $this->store->search($type, $vector, 25);
    $scores = array_map(fn (ChunkMatch $m) => $m->score, $matches);
    $sorted = $scores;
    rsort($sorted);

    expect($matches)->toHaveCount(25)
        ->and($scores)->toBe($sorted)
        ->and(array_unique(array_map(fn (ChunkMatch $m) => $m->modelId, $matches)))->toHaveCount(25);
});
