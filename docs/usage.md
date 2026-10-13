# Usage

The service provider and the `SemanticSearch` facade are registered through
package auto-discovery.

## 1. Make a model searchable

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

Only models with a scalar primary key can be indexed.

## 2. Index

```bash
php artisan semantic-search:index "App\Models\Article"          # or just: Article
php artisan semantic-search:index Article --chunk=200           # 200 models per DB query (default 100)
php artisan semantic-search:index Article --queue               # one queued job per model
php artisan semantic-search:index Article --fresh               # drop stored chunks first
```

A bare class name is resolved under `App\Models`. The model must implement
`Searchable`.

Or from code:

```php
use Plazorax\SemanticSearch\Facades\SemanticSearch;
use Plazorax\SemanticSearch\Jobs\IndexModel;

SemanticSearch::index($article);   // returns the number of chunks stored
$article->semanticIndex();         // same, via the trait
dispatch(new IndexModel($article)); // queued
SemanticSearch::remove($article);  // e.g. from a "deleted" model event
$article->semanticRemove();        // same, via the trait
SemanticSearch::flush(Article::class); // remove every chunk for the model type
```

Indexing is explicit. Nothing is embedded automatically on `save()`, so you
control when embedding API calls (and their cost) happen.

### Queued indexing

`--queue` dispatches one `IndexModel` job per model, on the connection and
queue set in `semantic-search.queue` (`SEMANTIC_SEARCH_QUEUE_CONNECTION`,
`SEMANTIC_SEARCH_QUEUE`; `null` uses the application default). If a model is
deleted before its job runs, the job is skipped silently.

## 3. Search

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
most one result per model (its best-matching chunk). The default limit is 10;
an empty query returns an empty collection. Models that were deleted, or that a
global scope hides (for example soft-deleted rows), are left out.
`SearchResult::toArray()` gives a JSON-ready shape for APIs:

```php
[
    'model_type' => 'App\Models\Article', // morph class
    'model_id' => 42,
    'score' => 0.71,
    'citation' => ['text' => '...', 'start' => 0, 'end' => 812, 'chunk_index' => 0],
]
```

A good `minScore` depends on the embedding model and your content. Start
without one, look at real scores, then pick a threshold.

## Testing your app

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
