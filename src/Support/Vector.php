<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Support;

use InvalidArgumentException;

final class Vector
{
    /**
     * Cosine similarity in the range [-1, 1]. Returns 0.0 when either vector
     * has zero magnitude.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot compare vectors of different dimensions (%d and %d).', count($a), count($b)
            ));
        }

        $dot = $normA = $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Scale a vector to unit length. Zero vectors are returned unchanged.
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        if ($norm === 0.0) {
            return $vector;
        }

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }
}
