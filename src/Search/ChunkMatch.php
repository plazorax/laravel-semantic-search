<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Search;

use Plazorax\SemanticSearch\Chunking\Chunk;

/**
 * A stored chunk returned by a vector store, with its similarity score.
 */
final readonly class ChunkMatch
{
    public function __construct(
        public string $modelType,
        public string $modelId,
        public Chunk $chunk,
        public float $score,
    ) {}
}
