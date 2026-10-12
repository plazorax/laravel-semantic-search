<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Contracts;

/**
 * Turns text into fixed-length embedding vectors.
 */
interface Embedder
{
    /**
     * Embed a batch of texts. The returned list has one vector per input,
     * in the same order as the input.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array;

    /**
     * The length of every vector this embedder returns.
     */
    public function dimensions(): int;
}
