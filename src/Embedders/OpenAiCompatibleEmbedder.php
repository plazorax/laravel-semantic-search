<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Embedders;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Plazorax\SemanticSearch\Contracts\Embedder;
use Plazorax\SemanticSearch\Exceptions\EmbeddingException;
use Throwable;

/**
 * Calls an OpenAI-compatible `POST {base_url}/embeddings` endpoint.
 *
 * Works with the OpenAI API and with servers that implement the same request
 * and response shape. Inputs are sent in batches of $batchSize. Connection
 * errors, HTTP 429 and 5xx responses are retried up to $retries times; other
 * errors (bad request, invalid key) fail on the first attempt.
 *
 * @see https://platform.openai.com/docs/api-reference/embeddings/create
 */
final readonly class OpenAiCompatibleEmbedder implements Embedder
{
    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        private ?string $apiKey,
        private string $model,
        private int $dimensions,
        private bool $sendDimensions = true,
        private int $batchSize = 64,
        private int $timeout = 30,
        private int $retries = 2,
    ) {
        if ($dimensions < 1) {
            throw new InvalidArgumentException('Dimensions must be at least 1.');
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be at least 1.');
        }
    }

    public function embed(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk($texts, max(1, $this->batchSize)) as $batch) {
            array_push($vectors, ...$this->embedBatch($batch));
        }

        return $vectors;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @param  list<string>  $batch
     * @return list<list<float>>
     */
    private function embedBatch(array $batch): array
    {
        $payload = ['model' => $this->model, 'input' => $batch];

        if ($this->sendDimensions) {
            $payload['dimensions'] = $this->dimensions;
        }

        $request = $this->http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->retry($this->retries + 1, 250, self::isRetryable(...), throw: false);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        try {
            $response = $request->post('embeddings', $payload);
        } catch (ConnectionException $e) {
            throw new EmbeddingException('Could not reach the embeddings endpoint: '.$e->getMessage(), 0, $e);
        }

        return $this->parse($response, count($batch));
    }

    private static function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->status() === 429 || $e->response->serverError());
    }

    /**
     * @return list<list<float>>
     */
    private function parse(Response $response, int $expected): array
    {
        if ($response->failed()) {
            throw new EmbeddingException(sprintf(
                'Embeddings request failed with HTTP %d: %s',
                $response->status(),
                mb_substr($response->body(), 0, 500),
            ));
        }

        $data = $response->json('data');

        if (! is_array($data) || count($data) !== $expected) {
            throw new EmbeddingException(sprintf(
                'Expected %d embeddings in the response, got %s.',
                $expected,
                is_array($data) ? count($data) : 'none',
            ));
        }

        $vectors = [];

        foreach ($data as $position => $item) {
            $embedding = is_array($item) ? ($item['embedding'] ?? null) : null;

            if (! is_array($item) || ! is_array($embedding) || count($embedding) !== $this->dimensions) {
                throw new EmbeddingException(sprintf(
                    'Embedding %d does not have the configured %d dimensions.', $position, $this->dimensions
                ));
            }

            $index = is_int($item['index'] ?? null) ? $item['index'] : $position;
            $vectors[$index] = array_values(array_map(static fn (mixed $v): float => (float) $v, $embedding));
        }

        ksort($vectors);

        return array_values($vectors);
    }
}
