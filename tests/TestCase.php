<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Plazorax\SemanticSearch\SemanticSearchServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public static function usesPostgres(): bool
    {
        return getenv('DB_CONNECTION') === 'pgsql';
    }

    protected function getPackageProviders($app): array
    {
        return [SemanticSearchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('semantic-search.embedder', 'fake');
        $config->set('semantic-search.dimensions', 64);
        $config->set('semantic-search.store', 'memory');
        $config->set('semantic-search.chunking', ['size' => 120, 'overlap' => 30]);

        if (static::usesPostgres()) {
            $config->set('database.default', 'pgsql');
            $config->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: 'postgres',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');

        if (static::usesPostgres()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
