<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Concerns;

use Illuminate\Database\Eloquent\Model;
use Plazorax\SemanticSearch\Contracts\Searchable;
use Plazorax\SemanticSearch\Search\PendingSearch;
use Plazorax\SemanticSearch\SemanticSearchManager;

/**
 * Convenience methods for models that implement the Searchable contract.
 *
 * Indexing is explicit: nothing is embedded on save, so you decide when an
 * embeddings API call happens (inline, from a queued job, or in bulk via the
 * semantic-search:index command).
 *
 * @phpstan-require-implements Searchable
 *
 * @phpstan-require-extends Model
 */
trait HasSemanticSearch
{
    /**
     * @return PendingSearch<static>
     */
    public static function semanticSearch(string $query): PendingSearch
    {
        return app(SemanticSearchManager::class)->for(static::class)->query($query);
    }

    /**
     * Chunk, embed and store this model. Returns the number of chunks stored.
     */
    public function semanticIndex(): int
    {
        return app(SemanticSearchManager::class)->index($this);
    }

    public function semanticRemove(): void
    {
        app(SemanticSearchManager::class)->remove($this);
    }
}
