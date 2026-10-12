<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Plazorax\SemanticSearch\Contracts\Embedder;
use Plazorax\SemanticSearch\Embedders\OpenAiCompatibleEmbedder;
use Plazorax\SemanticSearch\Exceptions\EmbeddingException;

function openAiEmbedder(array $overrides = []): OpenAiCompatibleEmbedder
{
    return new OpenAiCompatibleEmbedder(...array_merge([
        'http' => app(Factory::class),
        'baseUrl' => 'https://embeddings.test/v1/',
        'apiKey' => 'test-key',
        'model' => 'test-embedding-model',
        'dimensions' => 3,
        'retries' => 0,
    ], $overrides));
}

/**
 * Build an embeddings response, optionally with the items in reverse order.
 *
 * @param  list<list<float>>  $vectors
 */
function embeddingsResponse(array $vectors, bool $reversed = false): array
{
    $data = array_map(
        fn (array $vector, int $index): array => ['object' => 'embedding', 'index' => $index, 'embedding' => $vector],
        $vectors,
        array_keys($vectors),
    );

    return ['object' => 'list', 'data' => $reversed ? array_reverse($data) : $data, 'model' => 'test-embedding-model'];
}

it('posts to the embeddings endpoint and returns vectors in input order', function () {
    Http::fake(['embeddings.test/*' => Http::response(embeddingsResponse([[1, 0, 0], [0, 1, 0]], reversed: true))]);

    $vectors = openAiEmbedder()->embed(['first', 'second']);

    expect($vectors)->toBe([[1.0, 0.0, 0.0], [0.0, 1.0, 0.0]]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://embeddings.test/v1/embeddings'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request->data() === ['model' => 'test-embedding-model', 'input' => ['first', 'second'], 'dimensions' => 3]);
});

it('omits dimensions and the auth header when not configured', function () {
    Http::fake(['*' => Http::response(embeddingsResponse([[1, 2, 3]]))]);

    openAiEmbedder(['apiKey' => null, 'sendDimensions' => false])->embed(['x']);

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization')
        && ! array_key_exists('dimensions', $request->data()));
});

it('splits large inputs into batches', function () {
    Http::fake(function (Request $request) {
        $count = count($request->data()['input']);

        return Http::response(embeddingsResponse(array_fill(0, $count, [0.1, 0.2, 0.3])));
    });

    $vectors = openAiEmbedder(['batchSize' => 2])->embed(['a', 'b', 'c', 'd', 'e']);

    expect($vectors)->toHaveCount(5);
    Http::assertSentCount(3);
});

it('does not call the API for an empty input list', function () {
    Http::fake();

    expect(openAiEmbedder()->embed([]))->toBe([]);
    Http::assertNothingSent();
});

it('throws on an HTTP error', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

    openAiEmbedder()->embed(['x']);
})->throws(EmbeddingException::class, 'HTTP 401');

it('retries rate limits and server errors', function () {
    Sleep::fake();
    Http::fakeSequence()
        ->push(['error' => 'rate limited'], 429)
        ->push(['error' => 'unavailable'], 503)
        ->push(embeddingsResponse([[1, 0, 0]]));

    expect(openAiEmbedder(['retries' => 2])->embed(['x']))->toBe([[1.0, 0.0, 0.0]]);
    Http::assertSentCount(3);
});

it('does not retry client errors', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response(['error' => 'invalid api key'], 401)]);

    expect(fn () => openAiEmbedder(['retries' => 2])->embed(['x']))
        ->toThrow(EmbeddingException::class, 'HTTP 401');
    Http::assertSentCount(1);
});

it('throws when the vector length does not match the configured dimensions', function () {
    Http::fake(['*' => Http::response(embeddingsResponse([[1, 2]]))]);

    openAiEmbedder()->embed(['x']);
})->throws(EmbeddingException::class, 'dimensions');

it('throws when the number of embeddings does not match the input', function () {
    Http::fake(['*' => Http::response(embeddingsResponse([[1, 2, 3]]))]);

    openAiEmbedder()->embed(['x', 'y']);
})->throws(EmbeddingException::class, 'Expected 2 embeddings');

it('is the default embedder and reads its settings from config', function () {
    config([
        'semantic-search.embedder' => 'openai',
        'semantic-search.dimensions' => 2,
        'semantic-search.embedders.openai.base_url' => 'https://configured.test/v1',
        'semantic-search.embedders.openai.model' => 'configured-model',
    ]);
    Http::fake(['configured.test/*' => Http::response(embeddingsResponse([[0.6, 0.8]]))]);

    $embedder = app(Embedder::class);

    expect($embedder)->toBeInstanceOf(OpenAiCompatibleEmbedder::class)
        ->and($embedder->embed(['hello']))->toBe([[0.6, 0.8]]);

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'configured-model');
});
