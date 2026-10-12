<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Plazorax\SemanticSearch\Contracts\Embedder;
use Plazorax\SemanticSearch\Contracts\Searchable;
use Plazorax\SemanticSearch\Contracts\VectorStore;
use Plazorax\SemanticSearch\Indexer;

/**
 * Fluent semantic query against one model type.
 *
 * @template TModel of Model&Searchable
 */
final class PendingSearch
{
    private string $query = '';

    private int $limit = 10;

    private float $minScore = -1.0;

    /**
     * @param  class-string<TModel>  $modelClass
     */
    public function __construct(
        private readonly Embedder $embedder,
        private readonly VectorStore $store,
        private readonly string $modelClass,
    ) {}

    /**
     * @return $this
     */
    public function query(string $query): static
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @return $this
     */
    public function limit(int $limit): static
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be at least 1.');
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * Drop results whose cosine similarity is below $score (between -1 and 1).
     *
     * @return $this
     */
    public function minScore(float $score): static
    {
        if ($score < -1.0 || $score > 1.0) {
            throw new InvalidArgumentException('Minimum score must be between -1 and 1.');
        }

        $this->minScore = $score;

        return $this;
    }

    /**
     * Run the search. Results are ordered by descending score; models that
     * no longer exist (or are hidden by global scopes) are skipped.
     *
     * @return Collection<int, SearchResult<TModel>>
     */
    public function get(): Collection
    {
        if (trim($this->query) === '') {
            return new Collection;
        }

        $prototype = new $this->modelClass;
        [$vector] = $this->embedder->embed([$this->query]);

        $matches = $this->store->search($prototype->getMorphClass(), $vector, $this->limit, $this->minScore);

        if ($matches === []) {
            return new Collection;
        }

        $models = $prototype->newQuery()
            ->whereKey(array_map(static fn (ChunkMatch $match): string => $match->modelId, $matches))
            ->get()
            ->keyBy(static fn (Model $model): string => Indexer::key($model));

        $results = [];

        foreach ($matches as $match) {
            $model = $models->get($match->modelId);

            if ($model instanceof $this->modelClass) {
                $results[] = new SearchResult($model, $match->score, $match->chunk);
            }
        }

        return new Collection($results);
    }
}
