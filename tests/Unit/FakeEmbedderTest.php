<?php

declare(strict_types=1);

use Plazorax\SemanticSearch\Embedders\FakeEmbedder;
use Plazorax\SemanticSearch\Support\Vector;

it('returns one vector of the configured length per input', function () {
    $vectors = (new FakeEmbedder(32))->embed(['one', 'two', 'three']);

    expect($vectors)->toHaveCount(3)
        ->and($vectors)->each->toHaveCount(32);
});

it('is deterministic across instances', function () {
    $text = 'Refund policy for damaged items';

    expect((new FakeEmbedder(64))->embed([$text]))->toBe((new FakeEmbedder(64))->embed([$text]));
});

it('produces unit-length vectors', function () {
    [$vector] = (new FakeEmbedder(64))->embed(['some words here']);

    expect(sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector))))->toEqualWithDelta(1.0, 1e-9);
});

it('ignores case and punctuation', function () {
    [$a, $b] = (new FakeEmbedder(64))->embed(['Shipping, Times!', 'shipping times']);

    expect($a)->toBe($b);
});

it('scores texts with shared words higher than unrelated texts', function () {
    [$query, $related, $unrelated] = (new FakeEmbedder(256))->embed([
        'international shipping times',
        'shipping times for international orders',
        'how to reset a forgotten password',
    ]);

    expect(Vector::cosine($query, $related))->toBeGreaterThan(Vector::cosine($query, $unrelated));
});

it('returns a zero vector for text without tokens', function () {
    [$vector] = (new FakeEmbedder(8))->embed(['  ...  ']);

    expect($vector)->toBe(array_fill(0, 8, 0.0));
});

it('records every embedded text', function () {
    $embedder = new FakeEmbedder;
    $embedder->embed(['a', 'b']);
    $embedder->embed(['c']);

    expect($embedder->calls())->toBe(['a', 'b', 'c'])
        ->and($embedder->dimensions())->toBe(256);
});

it('computes cosine similarity', function () {
    expect(Vector::cosine([1.0, 0.0], [2.0, 0.0]))->toEqualWithDelta(1.0, 1e-9)
        ->and(Vector::cosine([1.0, 0.0], [0.0, 3.0]))->toEqualWithDelta(0.0, 1e-9)
        ->and(Vector::cosine([1.0, 1.0], [-1.0, -1.0]))->toEqualWithDelta(-1.0, 1e-9)
        ->and(Vector::cosine([0.0, 0.0], [1.0, 0.0]))->toBe(0.0);
});

it('refuses to compare vectors of different lengths', function () {
    Vector::cosine([1.0], [1.0, 2.0]);
})->throws(InvalidArgumentException::class);
