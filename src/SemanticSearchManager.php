<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Plazorax\SemanticSearch\Contracts\Embedder;
use Plazorax\SemanticSearch\Contracts\Searchable;
use Plazorax\SemanticSearch\Contracts\VectorStore;
use Plazorax\SemanticSearch\Search\PendingSearch;

final readonly class SemanticSearchManager
{
    public function __construct(
        private Embedder $embedder,
        private VectorStore $store,
        private Indexer $indexer,
    ) {}

    /**
     * Start a search over one model class.
     *
     * @template TModel of Model&Searchable
     *
     * @param  class-string<TModel>  $modelClass
     * @return PendingSearch<TModel>
     */
    public function for(string $modelClass): PendingSearch
    {
        if (! is_subclass_of($modelClass, Model::class) || ! is_subclass_of($modelClass, Searchable::class)) {
            throw new InvalidArgumentException(sprintf(
                '[%s] must be an Eloquent model that implements %s.', $modelClass, Searchable::class
            ));
        }

        return new PendingSearch($this->embedder, $this->store, $modelClass);
    }

    /**
     * @return int The number of chunks stored.
     */
    public function index(Model&Searchable $model): int
    {
        return $this->indexer->index($model);
    }

    public function remove(Model $model): void
    {
        $this->indexer->remove($model);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function flush(string $modelClass): void
    {
        $this->indexer->flush($modelClass);
    }
}
