<?php

declare(strict_types=1);

use Plazorax\SemanticSearch\Chunking\Chunk;
use Plazorax\SemanticSearch\Search\ChunkMatch;
use Plazorax\SemanticSearch\Stores\EmbeddedChunk;
use Plazorax\SemanticSearch\Stores\InMemoryStore;
use Plazorax\SemanticSearch\Stores\PgvectorStore;

/**
 * @param  list<float>  $vector
 */
function embedded(int $index, array $vector, string $text = 'text'): EmbeddedChunk
{
    return new EmbeddedChunk(new Chunk($index, $text, 0, mb_strlen($text)), $vector);
}

beforeEach(function () {
    $this->store = new InMemoryStore;
    $this->store->replace('post', '1', [embedded(0, [1.0, 0.0], 'exact')]);
    $this->store->replace('post', '2', [embedded(0, [0.0, 1.0], 'orthogonal'), embedded(1, [0.8, 0.6], 'close')]);
    $this->store->replace('post', '3', [embedded(0, [-1.0, 0.0], 'opposite')]);
    $this->store->replace('page', '1', [embedded(0, [1.0, 0.0], 'other type')]);
});

it('ranks models by their best chunk', function () {
    $matches = $this->store->search('post', [1.0, 0.0], 10);

    expect(array_map(fn (ChunkMatch $m): string => $m->modelId, $matches))->toBe(['1', '2', '3'])
        ->and($matches[1]->chunk->text)->toBe('close')
        ->and($matches[1]->score)->toEqualWithDelta(0.8, 1e-9);
});

it('applies the limit and minimum score', function () {
    expect($this->store->search('post', [1.0, 0.0], 1))->toHaveCount(1)
        ->and($this->store->search('post', [1.0, 0.0], 10, 0.5))->toHaveCount(2)
        ->and($this->store->search('post', [1.0, 0.0], 10, 0.99))->toHaveCount(1);
});

it('keeps model types apart', function () {
    $matches = $this->store->search('page', [1.0, 0.0], 10);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->modelType)->toBe('page')
        ->and($matches[0]->chunk->text)->toBe('other type');
});

it('replaces, deletes and flushes', function () {
    $this->store->replace('post', '1', [embedded(0, [0.0, 1.0], 'replaced')]);
    expect($this->store->count('post', '1'))->toBe(1)
        ->and($this->store->search('post', [0.0, 1.0], 1)[0]->chunk->text)->toBe('replaced');

    $this->store->delete('post', '2');
    expect($this->store->count('post'))->toBe(2);

    $this->store->replace('post', '3', []);
    expect($this->store->count('post', '3'))->toBe(0);

    $this->store->flush('post');
    expect($this->store->search('post', [1.0, 0.0], 10))->toBe([])
        ->and($this->store->count('page'))->toBe(1);
});

it('formats pgvector literals', function () {
    expect(PgvectorStore::literal([0.5, -0.25, 1.0, 0.0]))->toBe('[0.5,-0.25,1,0]')
        ->and(PgvectorStore::literal([0.000012345]))->toBe('[1.2345e-5]');
});

it('formats pgvector literals the same way in any locale', function () {
    $previous = setlocale(LC_NUMERIC, '0');

    if (setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German') === false) {
        $this->markTestSkipped('No German locale installed.');
    }

    try {
        expect(PgvectorStore::literal([0.5, -0.25]))->toBe('[0.5,-0.25]');
    } finally {
        setlocale(LC_NUMERIC, $previous === false ? 'C' : $previous);
    }
});
