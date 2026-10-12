<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Contracts;

use Plazorax\SemanticSearch\Search\ChunkMatch;
use Plazorax\SemanticSearch\Stores\EmbeddedChunk;

/**
 * Persists chunk embeddings and finds the chunks closest to a query vector.
 */
interface VectorStore
{
    /**
     * Replace every stored chunk of one model with the given chunks.
     *
     * @param  list<EmbeddedChunk>  $chunks
     */
    public function replace(string $modelType, string $modelId, array $chunks): void;

    /**
     * Remove every stored chunk of one model.
     */
    public function delete(string $modelType, string $modelId): void;

    /**
     * Remove every stored chunk of one model type.
     */
    public function flush(string $modelType): void;

    /**
     * Return the best-matching chunk per model, ordered by descending cosine
     * similarity, keeping only matches whose score is at least $minScore.
     *
     * @param  list<float>  $vector
     * @return list<ChunkMatch>
     */
    public function search(string $modelType, array $vector, int $limit, float $minScore = -1.0): array;
}
