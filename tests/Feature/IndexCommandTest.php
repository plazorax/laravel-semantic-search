<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Plazorax\SemanticSearch\Contracts\VectorStore;
use Plazorax\SemanticSearch\Jobs\IndexModel;
use Plazorax\SemanticSearch\SemanticSearchManager;
use Plazorax\SemanticSearch\Tests\Fixtures\Article;
use Plazorax\SemanticSearch\Tests\Fixtures\Note;

beforeEach(function () {
    foreach (range(1, 5) as $i) {
        Article::create(['title' => "Article {$i}", 'body' => "Body of article number {$i}."]);
    }
});

it('indexes every record in chunks and reports progress', function () {
    $this->artisan('semantic-search:index', ['model' => Article::class, '--chunk' => 2])
        ->expectsOutputToContain('Indexing 5')
        ->expectsOutputToContain('Indexed 5 records into 5 chunks.')
        ->assertSuccessful();

    expect(app(VectorStore::class)->count((new Article)->getMorphClass()))->toBe(5);
});

it('accepts a class name with forward slashes', function () {
    $this->artisan('semantic-search:index', ['model' => str_replace('\\', '/', Article::class)])
        ->assertSuccessful();
});

it('dispatches queued jobs with --queue', function () {
    Bus::fake();
    config(['semantic-search.queue.queue' => 'embeddings']);

    $this->artisan('semantic-search:index', ['model' => Article::class, '--queue' => true])
        ->expectsOutputToContain('Dispatched 5 indexing jobs.')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(IndexModel::class, 5);
    Bus::assertDispatched(IndexModel::class, fn (IndexModel $job): bool => $job->queue === 'embeddings');
    expect(app(VectorStore::class)->count((new Article)->getMorphClass()))->toBe(0);
});

it('indexes a model when the queued job runs', function () {
    $article = Article::first();

    (new IndexModel($article))->handle(app(SemanticSearchManager::class));

    expect(app(VectorStore::class)->count($article->getMorphClass(), (string) $article->getKey()))->toBe(1);
});

it('removes stale chunks with --fresh', function () {
    $store = app(VectorStore::class);
    $this->artisan('semantic-search:index', ['model' => Article::class])->assertSuccessful();
    Article::query()->limit(2)->get()->each->delete();

    $this->artisan('semantic-search:index', ['model' => Article::class, '--fresh' => true])->assertSuccessful();

    expect($store->count((new Article)->getMorphClass()))->toBe(3);
});

it('fails for unknown or non-searchable models', function (string $model) {
    $this->artisan('semantic-search:index', ['model' => $model])
        ->expectsOutputToContain('was not found or does not implement')
        ->assertFailed();
})->with(['Missing\\Model', Note::class]);

it('fails for a non-positive --chunk', function () {
    $this->artisan('semantic-search:index', ['model' => Article::class, '--chunk' => 0])
        ->expectsOutputToContain('--chunk option must be a positive integer')
        ->assertFailed();
});
