<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Chunking;

/**
 * A slice of a model's searchable text.
 *
 * Offsets are character (not byte) positions in the original text, so
 * mb_substr($text, $chunk->start, $chunk->length()) returns $chunk->text.
 */
final readonly class Chunk
{
    public function __construct(
        public int $index,
        public string $text,
        public int $start,
        public int $end,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start;
    }
}
