# laravel-semantic-search

![CI](https://github.com/plazorax/laravel-semantic-search/actions/workflows/ci.yml/badge.svg)

A Laravel package for semantic (embedding-based) search over Eloquent models.
Each result comes with a citation: the exact chunk of text that matched, with
its character offsets in the source.

**Status:** v0.1.0 — reference implementation. The API may change before 1.0.

**Tested with:** PHP 8.4.25, Composer 2.10.3, Laravel 12.69.3 (Testbench 10.12.0)
and Laravel 11.57.0 (Testbench 9.18.0), Pest 4.7.8, Larastan 3.13.0 (level 8),
Pint 1.32.1, PostgreSQL 16.11 with pgvector 0.8.1 (integration tests). PHP 8.3
is covered by the CI matrix only; CI runs the pgvector tests against
`pgvector/pgvector:pg16`.

## Why

Keyword search misses results that use different words for the same thing
("refund" vs "money back"). Embedding search handles that, but in a
retrieval-augmented (RAG) feature or a help-centre search you also need to
show *where* the answer came from. This package keeps the moving parts small
and swappable:

- an `Embedder` contract (OpenAI-compatible HTTP driver, or a deterministic
  fake for tests),
- a `VectorStore` contract (PostgreSQL + pgvector, or in-memory),
- chunking with overlap and offsets, so every hit can be quoted and linked back
  to the source text.

## Quick start

Requirements: PHP 8.3+, Laravel 11 or 12. For the `pgvector` store, PostgreSQL
with the [pgvector](https://github.com/pgvector/pgvector) extension, version
0.8.0 or later (on older versions set `SEMANTIC_SEARCH_ITERATIVE_SCAN=null`; see
[How it works](#how-it-works)).

```bash
composer require plazorax/laravel-semantic-search

php artisan vendor:publish --tag=semantic-search-config
php artisan vendor:publish --tag=semantic-search-migrations
php artisan migrate
```

The service provider and the `SemanticSearch` facade are registered through
package auto-discovery.

`.env`:

```dotenv
SEMANTIC_SEARCH_API_KEY=sk-...            # falls back to OPENAI_API_KEY
SEMANTIC_SEARCH_MODEL=text-embedding-3-small
SEMANTIC_SEARCH_DIMENSIONS=1536           # must match the model and the migration
```

The migration runs `CREATE EXTENSION IF NOT EXISTS vector`, so the database
role needs permission to create extensions, or the extension must already be
enabled.

## Usage

### 1. Make a model searchable

```php
use Illuminate\Database\Eloquent\Model;
use Plazorax\SemanticSearch\Concerns\HasSemanticSearch;
use Plazorax\SemanticSearch\Contracts\Searchable;

class Article extends Model implements Searchable
{
    use HasSemanticSearch;

    public function toSearchableText(): string
    {
        return $this->title."\n\n".strip_tags($this->body);
    }
}
```

### 2. Index

```bash
php artisan semantic-search:index "App\Models\Article"          # or just: Article
php artisan semantic-search:index Article --chunk=200           # 200 models per DB query
php artisan semantic-search:index Article --queue               # one queued job per model
php artisan semantic-search:index Article --fresh               # drop stored chunks first
```

Or from code:

```php
use Plazorax\SemanticSearch\Facades\SemanticSearch;
use Plazorax\SemanticSearch\Jobs\IndexModel;

SemanticSearch::index($article);   // returns the number of chunks stored
$article->semanticIndex();         // same, via the trait
dispatch(new IndexModel($article)); // queued
SemanticSearch::remove($article);  // e.g. from a "deleted" model event
```

Indexing is explicit. Nothing is embedded automatically on `save()`, so you
control when embedding API calls (and their cost) happen.

### 3. Search

```php
$results = SemanticSearch::for(Article::class)
    ->query('how long does international delivery take?')
    ->limit(5)
    ->minScore(0.3)
    ->get();

foreach ($results as $result) {
    $result->model;            // Article instance
    $result->score;            // cosine similarity, -1..1
    $result->citation->text;   // the matched chunk
    $result->citation->start;  // character offsets into toSearchableText()
    $result->citation->end;
}

// Shorthand from the trait:
Article::semanticSearch('refund for a damaged item')->limit(3)->get();
```

`get()` returns a `Collection` of `SearchResult`, ordered by score, with at
most one result per model (its best-matching chunk). Models that were deleted,
or that a global scope hides (for example soft-deleted rows), are left out.
`SearchResult::toArray()` gives a JSON-ready shape for APIs.

A good `minScore` depends on the embedding model and your content. Start
without one, look at real scores, then pick a threshold.

### Other embedding providers

Any server that implements the OpenAI `POST /embeddings` request and response
shape works. Set the base URL and model, and turn off `send_dimensions` if the
server rejects the `dimensions` field:

```dotenv
SEMANTIC_SEARCH_BASE_URL=http://localhost:11434/v1
SEMANTIC_SEARCH_MODEL=nomic-embed-text
SEMANTIC_SEARCH_DIMENSIONS=768
SEMANTIC_SEARCH_SEND_DIMENSIONS=false
```

You can also set `embedder` or `store` in `config/semantic-search.php` to the
class name of your own `Embedder` or `VectorStore` implementation; it is
resolved from the container.

### Testing your app

Use the fake embedder and the in-memory store, so tests make no HTTP calls and
need no PostgreSQL:

```php
config([
    'semantic-search.embedder' => 'fake',
    'semantic-search.store' => 'memory',
    'semantic-search.dimensions' => 64,
]);
```

`FakeEmbedder` uses feature hashing over word tokens: the same text always
gets the same vector, and texts that share words score higher than texts that
do not. It does not understand meaning (synonyms score zero), so assert on
ranking of clearly related text, not on exact scores.

## How it works

1. **Chunking.** `toSearchableText()` is split by `TextChunker` into chunks of
   at most `chunking.size` characters (default 1000), each repeating up to
   `chunking.overlap` characters (default 200) of the previous one. Chunks end
   on whitespace when that keeps them at least half the target size, and start
   on a word boundary. Offsets are in characters (multibyte-safe), so
   `mb_substr($text, $start, $end - $start)` returns the chunk text.
2. **Embedding.** Chunks are sent in batches (`batch_size`, default 64) to the
   embeddings endpoint. The response is validated: one vector per input, each
   with the configured number of dimensions. Connection errors, HTTP 429 and
   5xx responses are retried (`retries`, default 2); other errors fail at once.
3. **Storing.** Re-indexing a model replaces all of its chunks in one
   transaction. Rows are keyed by the model's morph class and primary key, so
   morph maps are respected.
4. **Searching.** The query is embedded and the store returns the best chunk
   per model. `PgvectorStore` orders by the cosine distance operator `<=>`
   (served by the HNSW index from the migration), takes
   `limit × candidate_multiplier` nearest chunks, and keeps the best one per
   model. Score is `1 - cosine distance`. HNSW applies the `model_type` filter
   after the index scan, and a scan returns at most `hnsw.ef_search` rows
   (default 40), so a model type with few rows in a shared table could get
   too few results. Each search therefore runs in a transaction that sets
   `hnsw.ef_search` to the candidate count (40–1000) and `hnsw.iterative_scan`
   (pgvector 0.8+, default `relaxed_order`); the integration tests cover this
   case. `InMemoryStore` scores every chunk exactly. Models are then loaded with
   one `whereKey()` query.

Sources for the conventions used:
[OpenAI embeddings API reference](https://platform.openai.com/docs/api-reference/embeddings/create),
[pgvector README](https://github.com/pgvector/pgvector) (operators, HNSW, filtering, iterative index scans, limits),
[Laravel package development](https://laravel.com/docs/12.x/packages),
[Laravel HTTP client](https://laravel.com/docs/12.x/http-client),
[Laravel queues](https://laravel.com/docs/12.x/queues).

## Development

```bash
composer install
composer test      # Pest (pgvector tests are skipped without PostgreSQL)
composer analyse   # Larastan, level 8
composer lint      # Pint --test
```

To run the pgvector tests locally, start a database with pgvector 0.8+ and
point the suite at it:

```bash
docker run --rm -d -p 5432:5432 -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=testing pgvector/pgvector:pg16
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_USERNAME=postgres DB_PASSWORD=postgres vendor/bin/pest
```

## Limitations and roadmap

Limitations in v0.1:

- **One result per model, but candidates are limited.** `PgvectorStore`
  de-duplicates in PHP after fetching `limit × candidate_multiplier` chunks.
  If one long document has many near-identical chunks, fewer than `limit`
  models can come back. Raise `candidate_multiplier` if this happens.
- **Approximate search.** HNSW trades recall for speed. Iterative scans stop
  after `hnsw.max_scan_tuples` (pgvector default 20,000), so a model type that
  is a tiny fraction of a very large table can still get fewer results.
- **No filtering by attributes** (tenant, status, locale) inside the vector
  query. Global scopes are applied when models are loaded, which can also
  reduce the result count.
- **Character-based chunking**, not token-based. Keep `chunking.size` well
  under your embedding model's input limit.
- **No automatic sync** on model events and no change detection; re-indexing
  always re-embeds.
- **Dimensions are fixed** by the migration. pgvector's HNSW index supports up
  to 2,000 dimensions for the `vector` type; larger models need a different
  column type or index.
- `InMemoryStore` is per process and not persisted. `FakeEmbedder` is for tests
  only.
- Laravel 11 is past its security-support window; CI still checks
  compatibility with it, but new projects should use Laravel 12.

Possible next steps:

- Opt-in observer to index on save and remove on delete.
- Metadata filters stored with each chunk.
- Hybrid ranking (full-text + vector).
- Skip re-embedding unchanged chunks (content hash).
- More stores (SQLite with sqlite-vec, Qdrant).

## License

MIT. See [LICENSE](LICENSE).
