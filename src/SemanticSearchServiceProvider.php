<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Plazorax\SemanticSearch\Chunking\TextChunker;
use Plazorax\SemanticSearch\Console\IndexCommand;
use Plazorax\SemanticSearch\Contracts\Embedder;
use Plazorax\SemanticSearch\Contracts\VectorStore;
use Plazorax\SemanticSearch\Embedders\FakeEmbedder;
use Plazorax\SemanticSearch\Embedders\OpenAiCompatibleEmbedder;
use Plazorax\SemanticSearch\Stores\InMemoryStore;
use Plazorax\SemanticSearch\Stores\PgvectorStore;

final class SemanticSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/semantic-search.php', 'semantic-search');

        $this->app->singleton(Embedder::class, fn (Application $app): Embedder => $this->makeEmbedder($app));
        $this->app->singleton(VectorStore::class, fn (Application $app): VectorStore => $this->makeStore($app));

        $this->app->singleton(TextChunker::class, function (Application $app): TextChunker {
            $config = $app->make(Config::class);

            return new TextChunker(
                (int) $config->get('semantic-search.chunking.size', 1000),
                (int) $config->get('semantic-search.chunking.overlap', 200),
            );
        });

        $this->app->singleton(Indexer::class);
        $this->app->singleton(SemanticSearchManager::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/semantic-search.php' => $this->app->configPath('semantic-search.php'),
        ], 'semantic-search-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'semantic-search-migrations');

        $this->commands([IndexCommand::class]);
    }

    private function makeEmbedder(Application $app): Embedder
    {
        $config = $app->make(Config::class);
        $driver = (string) $config->get('semantic-search.embedder', 'openai');
        $dimensions = (int) $config->get('semantic-search.dimensions', 1536);
        $options = (array) $config->get("semantic-search.embedders.{$driver}", []);

        return match ($driver) {
            'openai' => new OpenAiCompatibleEmbedder(
                http: $app->make(HttpFactory::class),
                baseUrl: (string) ($options['base_url'] ?? 'https://api.openai.com/v1'),
                apiKey: isset($options['api_key']) ? (string) $options['api_key'] : null,
                model: (string) ($options['model'] ?? 'text-embedding-3-small'),
                dimensions: $dimensions,
                sendDimensions: (bool) ($options['send_dimensions'] ?? true),
                batchSize: (int) ($options['batch_size'] ?? 64),
                timeout: (int) ($options['timeout'] ?? 30),
                retries: (int) ($options['retries'] ?? 2),
            ),
            'fake' => new FakeEmbedder($dimensions),
            default => $this->makeCustom($app, $driver, Embedder::class),
        };
    }

    private function makeStore(Application $app): VectorStore
    {
        $config = $app->make(Config::class);
        $driver = (string) $config->get('semantic-search.store', 'pgvector');
        $options = (array) $config->get("semantic-search.stores.{$driver}", []);

        return match ($driver) {
            'pgvector' => new PgvectorStore(
                connection: $app->make(DatabaseManager::class)->connection(
                    isset($options['connection']) ? (string) $options['connection'] : null
                ),
                table: (string) ($options['table'] ?? 'semantic_search_chunks'),
                candidateMultiplier: (int) ($options['candidate_multiplier'] ?? 4),
                iterativeScan: array_key_exists('iterative_scan', $options)
                    ? (isset($options['iterative_scan']) ? (string) $options['iterative_scan'] : null)
                    : 'relaxed_order',
            ),
            'memory' => new InMemoryStore,
            default => $this->makeCustom($app, $driver, VectorStore::class),
        };
    }

    /**
     * Resolve a custom driver given as a class name from the container.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private function makeCustom(Application $app, string $class, string $contract): object
    {
        if (! class_exists($class) || ! is_subclass_of($class, $contract)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown semantic search driver [%s]: expected a built-in name or a class implementing %s.', $class, $contract
            ));
        }

        /** @var T */
        return $app->make($class);
    }
}
