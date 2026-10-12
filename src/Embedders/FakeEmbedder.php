<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Embedders;

use InvalidArgumentException;
use Plazorax\SemanticSearch\Contracts\Embedder;
use Plazorax\SemanticSearch\Support\Vector;

/**
 * Deterministic, offline embedder for tests and local development.
 *
 * Uses feature hashing over lower-cased word tokens: each token adds +1 or -1
 * to one bucket chosen by its hash, and the result is normalised to unit
 * length. Texts that share words therefore score higher than texts that do
 * not, which is enough to test ranking without a network call. It does not
 * capture meaning (synonyms score zero).
 */
final class FakeEmbedder implements Embedder
{
    /** @var list<string> */
    private array $calls = [];

    public function __construct(private readonly int $dimensions = 256)
    {
        if ($dimensions < 1) {
            throw new InvalidArgumentException('Dimensions must be at least 1.');
        }
    }

    public function embed(array $texts): array
    {
        array_push($this->calls, ...$texts);

        return array_map($this->embedOne(...), $texts);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Every text passed to embed() so far, in call order.
     *
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<float>
     */
    private function embedOne(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);

        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        foreach ($matches[0] as $token) {
            $hash = hash('xxh64', $token);
            $bucket = (int) (hexdec(substr($hash, 0, 7)) % $this->dimensions);
            $sign = hexdec(substr($hash, 7, 1)) % 2 === 0 ? 1.0 : -1.0;
            $vector[$bucket] += $sign;
        }

        return Vector::normalize(array_values($vector));
    }
}
