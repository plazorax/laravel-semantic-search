<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Stores;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Plazorax\SemanticSearch\Chunking\Chunk;
use Plazorax\SemanticSearch\Contracts\VectorStore;
use Plazorax\SemanticSearch\Search\ChunkMatch;

/**
 * Stores chunk embeddings in a PostgreSQL table with a pgvector column.
 *
 * Search orders by the cosine distance operator (<=>), which the HNSW index
 * created by the published migration can serve, fetches
 * $limit * $candidateMultiplier nearest chunks, then keeps the best chunk per
 * model. Score = 1 - cosine distance.
 *
 * HNSW applies the model_type filter after scanning the index, and by default
 * a scan yields at most hnsw.ef_search (40) rows. Each search therefore raises
 * hnsw.ef_search to the candidate count and, unless $iterativeScan is null,
 * enables pgvector 0.8's iterative index scan so filtered queries still fill
 * the limit. Both settings are SET LOCAL inside a transaction.
 *
 * @see https://github.com/pgvector/pgvector#querying
 * @see https://github.com/pgvector/pgvector#iterative-index-scans
 */
final readonly class PgvectorStore implements VectorStore
{
    /** Values accepted by pgvector's hnsw.iterative_scan setting. */
    public const ITERATIVE_SCAN_MODES = ['off', 'strict_order', 'relaxed_order'];

    /** pgvector accepts hnsw.ef_search values from 1 to 1000; 40 is its default. */
    private const MIN_EF_SEARCH = 40;

    private const MAX_EF_SEARCH = 1000;

    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'semantic_search_chunks',
        private int $candidateMultiplier = 4,
        private ?string $iterativeScan = 'relaxed_order',
    ) {
        if ($candidateMultiplier < 1) {
            throw new InvalidArgumentException('Candidate multiplier must be at least 1.');
        }

        if ($iterativeScan !== null && ! in_array($iterativeScan, self::ITERATIVE_SCAN_MODES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Iterative scan must be null or one of: %s.', implode(', ', self::ITERATIVE_SCAN_MODES)
            ));
        }
    }

    public function replace(string $modelType, string $modelId, array $chunks): void
    {
        $this->connection->transaction(function () use ($modelType, $modelId, $chunks): void {
            $this->delete($modelType, $modelId);

            $now = now();
            $rows = array_map(static fn (EmbeddedChunk $embedded): array => [
                'model_type' => $modelType,
                'model_id' => $modelId,
                'chunk_index' => $embedded->chunk->index,
                'start_offset' => $embedded->chunk->start,
                'end_offset' => $embedded->chunk->end,
                'content' => $embedded->chunk->text,
                'embedding' => self::literal($embedded->vector),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunks);

            foreach (array_chunk($rows, 500) as $batch) {
                $this->connection->table($this->table)->insert($batch);
            }
        });
    }

    public function delete(string $modelType, string $modelId): void
    {
        $this->connection->table($this->table)
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->delete();
    }

    public function flush(string $modelType): void
    {
        $this->connection->table($this->table)->where('model_type', $modelType)->delete();
    }

    public function search(string $modelType, array $vector, int $limit, float $minScore = -1.0): array
    {
        if ($limit < 1) {
            return [];
        }

        $candidates = $limit * $this->candidateMultiplier;
        $literal = self::literal($vector);

        $rows = $this->connection->transaction(function () use ($modelType, $literal, $candidates) {
            $efSearch = max(self::MIN_EF_SEARCH, min(self::MAX_EF_SEARCH, $candidates));
            $this->connection->statement(sprintf('SET LOCAL hnsw.ef_search = %d', $efSearch));

            if ($this->iterativeScan !== null) {
                // Validated against ITERATIVE_SCAN_MODES in the constructor.
                $this->connection->statement(sprintf('SET LOCAL hnsw.iterative_scan = %s', $this->iterativeScan));
            }

            return $this->connection->table($this->table)
                ->select(['model_id', 'chunk_index', 'start_offset', 'end_offset', 'content'])
                ->selectRaw('1 - (embedding <=> CAST(? AS vector)) AS score', [$literal])
                ->where('model_type', $modelType)
                ->orderByRaw('embedding <=> CAST(? AS vector)', [$literal])
                ->limit($candidates)
                ->get();
        });

        $best = [];

        foreach ($rows as $row) {
            /** @var object{model_id: string, chunk_index: int|string, start_offset: int|string, end_offset: int|string, content: string, score: float|string} $row */
            $score = (float) $row->score;
            $modelId = (string) $row->model_id;

            // relaxed_order may return rows slightly out of distance order,
            // so compare scores instead of keeping the first row per model.
            if ($score < $minScore || (isset($best[$modelId]) && $best[$modelId]->score >= $score)) {
                continue;
            }

            $best[$modelId] = new ChunkMatch(
                $modelType,
                $modelId,
                new Chunk((int) $row->chunk_index, $row->content, (int) $row->start_offset, (int) $row->end_offset),
                $score,
            );
        }

        usort($best, static fn (ChunkMatch $a, ChunkMatch $b): int => $b->score <=> $a->score);

        return array_slice($best, 0, $limit);
    }

    /**
     * Format a vector as a pgvector text literal, e.g. "[0.1,0.2,0.3]".
     *
     * @param  list<float>  $vector
     */
    public static function literal(array $vector): string
    {
        return '['.implode(',', array_map(static fn (float $v): string => sprintf('%.9h', $v), $vector)).']';
    }
}
