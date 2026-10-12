<?php

declare(strict_types=1);

use Plazorax\SemanticSearch\Contracts\Embedder;
use Plazorax\SemanticSearch\Contracts\VectorStore;
use Plazorax\SemanticSearch\Embedders\FakeEmbedder;
use Plazorax\SemanticSearch\Stores\InMemoryStore;
use Plazorax\SemanticSearch\Stores\PgvectorStore;

it('resolves the configured drivers as singletons', function () {
    expect(app(Embedder::class))->toBeInstanceOf(FakeEmbedder::class)
        ->and(app(Embedder::class)->dimensions())->toBe(64)
        ->and(app(VectorStore::class))->toBeInstanceOf(InMemoryStore::class)
        ->and(app(VectorStore::class))->toBe(app(VectorStore::class));
});

it('resolves the pgvector store', function () {
    config(['semantic-search.store' => 'pgvector']);

    expect(app(VectorStore::class))->toBeInstanceOf(PgvectorStore::class);
});

it('rejects an unknown pgvector iterative scan mode', function () {
    config(['semantic-search.store' => 'pgvector', 'semantic-search.stores.pgvector.iterative_scan' => 'fast']);

    app(VectorStore::class);
})->throws(InvalidArgumentException::class, 'Iterative scan');

it('accepts a custom driver class', function () {
    config(['semantic-search.store' => InMemoryStore::class]);

    expect(app(VectorStore::class))->toBeInstanceOf(InMemoryStore::class);
});

it('rejects an unknown driver', function () {
    config(['semantic-search.embedder' => 'nope']);

    app(Embedder::class);
})->throws(InvalidArgumentException::class, 'Unknown semantic search driver [nope]');

it('registers the index command', function () {
    $this->artisan('list')->expectsOutputToContain('semantic-search:index');
});
