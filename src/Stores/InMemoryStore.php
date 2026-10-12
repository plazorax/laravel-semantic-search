<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Stores;

use Plazorax\SemanticSearch\Contracts\VectorStore;
use Plazorax\SemanticSearch\Search\ChunkMatch;
use Plazorax\SemanticSearch\Support\Vector;

/**
 * Keeps embeddings in a PHP array for the lifetime of the process.
 *
 * Search is exact (every chunk is scored). Suitable for tests, demos and
 * small datasets that are re-indexed on boot; nothing is persisted.
 */
final class InMemoryStore implements VectorStore
{
    /** @var array<string, array<string, list<EmbeddedChunk>>> */
    private array $chunks = [];

    public function replace(string $modelType, string $modelId, array $chunks): void
    {
        if ($chunks === []) {
            $this->delete($modelType, $modelId);

            return;
        }

        $this->chunks[$modelType][$modelId] = $chunks;
    }

    public function delete(string $modelType, string $modelId): void
    {
        unset($this->chunks[$modelType][$modelId]);
    }

    public function flush(string $modelType): void
    {
        unset($this->chunks[$modelType]);
    }

    public function search(string $modelType, array $vector, int $limit, float $minScore = -1.0): array
    {
        $best = [];

        foreach ($this->chunks[$modelType] ?? [] as $modelId => $chunks) {
            foreach ($chunks as $stored) {
                $score = Vector::cosine($vector, $stored->vector);

                if ($score < $minScore) {
                    continue;
                }

                if (! isset($best[$modelId]) || $score > $best[$modelId]->score) {
                    $best[$modelId] = new ChunkMatch($modelType, (string) $modelId, $stored->chunk, $score);
                }
            }
        }

        usort($best, static fn (ChunkMatch $a, ChunkMatch $b): int => $b->score <=> $a->score);

        return array_slice($best, 0, max(0, $limit));
    }

    /**
     * Number of chunks stored for a model type (or for one model).
     */
    public function count(string $modelType, ?string $modelId = null): int
    {
        if ($modelId !== null) {
            return count($this->chunks[$modelType][$modelId] ?? []);
        }

        return array_sum(array_map(count(...), $this->chunks[$modelType] ?? []));
    }
}
