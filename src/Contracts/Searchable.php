<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Contracts;

/**
 * Implemented by Eloquent models that can be indexed for semantic search.
 */
interface Searchable
{
    /**
     * The plain text that is chunked, embedded and cited in results.
     */
    public function toSearchableText(): string;
}
