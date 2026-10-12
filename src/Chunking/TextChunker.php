<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Chunking;

use InvalidArgumentException;

/**
 * Splits text into overlapping, character-bounded chunks.
 *
 * Chunks never exceed $size characters. When possible a chunk ends on
 * whitespace (as long as that keeps it at least half the target size), and
 * the next chunk starts on a word boundary inside the overlap window, so
 * citations do not begin or end mid-word.
 */
final readonly class TextChunker
{
    public function __construct(
        public int $size = 1000,
        public int $overlap = 200,
    ) {
        if ($size < 1) {
            throw new InvalidArgumentException('Chunk size must be at least 1 character.');
        }

        if ($overlap < 0 || $overlap >= $size) {
            throw new InvalidArgumentException('Chunk overlap must be between 0 and the chunk size minus one.');
        }
    }

    /**
     * @return list<Chunk>
     */
    public function chunk(string $text): array
    {
        $chars = mb_str_split($text);
        $length = count($chars);
        $chunks = [];
        $start = $this->skipWhitespace($chars, 0, $length);

        while ($start < $length) {
            $end = min($start + $this->size, $length);

            if ($end < $length && ! $this->isSpace($chars[$end])) {
                $end = $this->backOffToWhitespace($chars, $start, $end);
            }

            $end = $this->trimTrailingWhitespace($chars, $start, $end);

            $chunks[] = new Chunk(
                index: count($chunks),
                text: implode('', array_slice($chars, $start, $end - $start)),
                start: $start,
                end: $end,
            );

            if ($end >= $length) {
                break;
            }

            $start = $this->skipWhitespace($chars, $this->nextStart($chars, $start, $end), $length);
        }

        return $chunks;
    }

    /**
     * @param  list<string>  $chars
     */
    private function backOffToWhitespace(array $chars, int $start, int $end): int
    {
        $minimum = $start + intdiv($this->size, 2);

        for ($i = $end - 1; $i > $minimum; $i--) {
            if ($this->isSpace($chars[$i])) {
                return $i;
            }
        }

        return $end;
    }

    /**
     * @param  list<string>  $chars
     */
    private function nextStart(array $chars, int $start, int $end): int
    {
        $next = $end - $this->overlap;

        if ($next <= $start) {
            return $end;
        }

        // Move forward to the start of the next word, but never past $end.
        for ($i = $next; $i < $end; $i++) {
            if ($this->isSpace($chars[$i - 1])) {
                return $i;
            }
        }

        return $end;
    }

    /**
     * @param  list<string>  $chars
     */
    private function skipWhitespace(array $chars, int $position, int $length): int
    {
        while ($position < $length && $this->isSpace($chars[$position])) {
            $position++;
        }

        return $position;
    }

    /**
     * @param  list<string>  $chars
     */
    private function trimTrailingWhitespace(array $chars, int $start, int $end): int
    {
        while ($end > $start && $this->isSpace($chars[$end - 1])) {
            $end--;
        }

        return $end;
    }

    private function isSpace(string $char): bool
    {
        return preg_match('/^\s$/u', $char) === 1;
    }
}
