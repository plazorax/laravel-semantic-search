# laravel-semantic-search

![CI](https://github.com/plazorax/laravel-semantic-search/actions/workflows/ci.yml/badge.svg)

Semantic (embedding-based) search over Eloquent models for Laravel 11 and 12. Every result comes with a citation: the chunk of text that matched, with its character offsets in the source.

## Why

- Keyword search misses matches that use different words for the same thing ("refund" vs "money back"). Embeddings catch them.
- RAG features and help-centre search need to show where an answer came from, not only which record matched.
- The moving parts are small contracts (`Embedder`, `VectorStore`) that you can swap or replace.

## Install

Requires PHP 8.3+, Laravel 11 or 12, and PostgreSQL with the pgvector extension for the default store. The package is not on Packagist, so add the GitHub repository first:

```bash
composer config repositories.laravel-semantic-search vcs https://github.com/plazorax/laravel-semantic-search
composer require plazorax/laravel-semantic-search:^0.1

php artisan vendor:publish --tag=semantic-search-config
php artisan vendor:publish --tag=semantic-search-migrations
php artisan migrate
```

Set `SEMANTIC_SEARCH_API_KEY` in `.env` (falls back to `OPENAI_API_KEY`). The migration runs `CREATE EXTENSION IF NOT EXISTS vector`, so the database role needs that permission. See [configuration](docs/configuration.md).

## Usage

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

```bash
php artisan semantic-search:index Article   # add --queue for one job per model
```

```php
use Plazorax\SemanticSearch\Facades\SemanticSearch;

$results = SemanticSearch::for(Article::class)
    ->query('how long does international delivery take?')
    ->limit(5)
    ->get();

foreach ($results as $result) {
    $result->model;            // Article instance
    $result->score;            // cosine similarity, -1..1
    $result->citation->text;   // the matched chunk
    $result->citation->start;  // character offsets into toSearchableText()
}
```

Indexing is explicit: nothing is embedded on `save()`. More in [docs/usage.md](docs/usage.md).

## Features

- One result per model, with its best-matching chunk as the citation.
- Overlapping, word-aligned chunks with multibyte-safe character offsets.
- OpenAI-compatible embeddings driver with batching and retries; works with any server that implements the same `/embeddings` API.
- PostgreSQL + pgvector store with an HNSW cosine index, and an in-memory store for tests.
- `semantic-search:index` command with batched loading, queued jobs and `--fresh`.
- Deterministic `FakeEmbedder`, so app tests make no HTTP calls.
- Custom `Embedder` and `VectorStore` classes resolved from the container.

## Status

v0.1.0 — reference implementation; the API may change before 1.0. The pgvector store needs pgvector 0.8+ for iterative index scans (set `SEMANTIC_SEARCH_ITERATIVE_SCAN=null` on older versions), and models are not re-indexed automatically on save. See [limitations](docs/limitations.md), [how it works](docs/how-it-works.md), [pgvector notes](docs/pgvector.md) and [development](docs/development.md).

## License

[MIT](LICENSE) © 2026 Oleksii Shaposhnikov
