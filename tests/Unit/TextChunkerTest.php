<?php

declare(strict_types=1);

use Plazorax\SemanticSearch\Chunking\Chunk;
use Plazorax\SemanticSearch\Chunking\TextChunker;

function sampleText(int $words = 120): string
{
    $vocabulary = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel', 'india', 'juliet'];

    return implode(' ', array_map(fn (int $i): string => $vocabulary[$i % 10].$i, range(1, $words)));
}

it('returns no chunks for empty or whitespace-only text', function (string $text) {
    expect((new TextChunker(50, 10))->chunk($text))->toBe([]);
})->with(['', '   ', "\n\t \n"]);

it('returns a single chunk for short text', function () {
    $chunks = (new TextChunker(100, 20))->chunk('Short text.');

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toEqual(new Chunk(0, 'Short text.', 0, 11));
});

it('trims surrounding whitespace and keeps offsets pointing into the original text', function () {
    $text = "  \n Padded text.  ";
    $chunk = (new TextChunker(100, 20))->chunk($text)[0];

    expect($chunk->text)->toBe('Padded text.')
        ->and(mb_substr($text, $chunk->start, $chunk->length()))->toBe('Padded text.');
});

it('splits long text into bounded, overlapping chunks that cover the whole text', function () {
    $text = sampleText();
    $chunks = (new TextChunker(100, 30))->chunk($text);

    expect(count($chunks))->toBeGreaterThan(5)
        ->and($chunks[0]->start)->toBe(0)
        ->and(end($chunks)->end)->toBe(mb_strlen($text));

    foreach ($chunks as $i => $chunk) {
        expect($chunk->index)->toBe($i)
            ->and($chunk->length())->toBeLessThanOrEqual(100)
            ->and(mb_substr($text, $chunk->start, $chunk->length()))->toBe($chunk->text);

        if ($i > 0) {
            $previous = $chunks[$i - 1];
            expect($chunk->start)->toBeLessThan($previous->end)
                ->and($chunk->start)->toBeGreaterThan($previous->start);
        }
    }
});

it('breaks on whitespace so no word is cut in half', function () {
    $text = sampleText();
    $words = explode(' ', $text);

    foreach ((new TextChunker(100, 30))->chunk($text) as $chunk) {
        foreach (explode(' ', $chunk->text) as $word) {
            expect($words)->toContain($word);
        }
    }
});

it('produces adjacent chunks without overlap when overlap is zero', function () {
    $chunks = (new TextChunker(80, 0))->chunk(sampleText(60));

    foreach (array_slice($chunks, 1) as $i => $chunk) {
        expect($chunk->start)->toBeGreaterThanOrEqual($chunks[$i]->end);
    }
});

it('hard-splits a single word that is longer than the chunk size', function () {
    $chunks = (new TextChunker(10, 2))->chunk(str_repeat('x', 25));

    expect(array_map(fn (Chunk $c): int => $c->length(), $chunks))->each->toBeLessThanOrEqual(10)
        ->and($chunks[0]->text)->toBe(str_repeat('x', 10))
        ->and(end($chunks)->end)->toBe(25);
});

it('measures offsets in characters for multibyte text', function () {
    $text = str_repeat('Привіт світе, ünïcödé 🙂 текст. ', 20);
    $chunks = (new TextChunker(60, 15))->chunk($text);

    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->text))->toBeLessThanOrEqual(60)
            ->and(mb_substr($text, $chunk->start, $chunk->length()))->toBe($chunk->text);
    }
});

it('rejects invalid settings', function (int $size, int $overlap) {
    new TextChunker($size, $overlap);
})->with([
    'zero size' => [0, 0],
    'negative overlap' => [10, -1],
    'overlap equal to size' => [10, 10],
])->throws(InvalidArgumentException::class);
