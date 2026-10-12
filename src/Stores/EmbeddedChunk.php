<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Stores;

use Plazorax\SemanticSearch\Chunking\Chunk;

/**
 * A chunk paired with its embedding, ready to be written to a store.
 */
final readonly class EmbeddedChunk
{
    /**
     * @param  list<float>  $vector
     */
    public function __construct(
        public Chunk $chunk,
        public array $vector,
    ) {}
}
