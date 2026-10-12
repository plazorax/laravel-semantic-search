<?php

declare(strict_types=1);

use Plazorax\SemanticSearch\Contracts\Embedder;
use Plazorax\SemanticSearch\Contracts\VectorStore;
use Plazorax\SemanticSearch\Facades\SemanticSearch;
use Plazorax\SemanticSearch\Search\SearchResult;
use Plazorax\SemanticSearch\Tests\Fixtures\Article;
use Plazorax\SemanticSearch\Tests\Fixtures\Note;

beforeEach(function () {
    $this->shipping = Article::create([
        'title' => 'Shipping times',
        'body' => 'Domestic orders ship within two business days. International shipping takes seven to fourteen days depending on customs.',
    ]);
    $this->returns = Article::create([
        'title' => 'Returns and refunds',
        'body' => 'You can return unused items within thirty days. Refunds are issued to the original payment method after inspection.',
    ]);
    $this->account = Article::create([
        'title' => 'Account security',
        'body' => 'Reset a forgotten password from the login page. Enable two-factor authentication in your account settings.',
    ]);

    foreach ([$this->shipping, $this->returns, $this->account] as $article) {
        SemanticSearch::index($article);
    }
});

it('returns models ranked by similarity with a score and citation', function () {
    $results = SemanticSearch::for(Article::class)->query('how long does international shipping take')->get();

    expect($results)->not->toBeEmpty()
        ->and($results->first())->toBeInstanceOf(SearchResult::class)
        ->and($results->first()->model->is($this->shipping))->toBeTrue()
        ->and($results->first()->citation->text)->toContain('International shipping');

    $scores = $results->map(fn (SearchResult $r): float => $r->score)->all();
    expect($scores)->toBe(collect($scores)->sortDesc()->values()->all());
});

it('returns citations whose offsets point into the searchable text', function () {
    $result = SemanticSearch::for(Article::class)->query('refunds payment method')->get()->first();
    $source = $result->model->toSearchableText();

    expect(mb_substr($source, $result->citation->start, $result->citation->length()))->toBe($result->citation->text);
});

it('applies limit and minimum score', function () {
    $search = fn () => SemanticSearch::for(Article::class)->query('reset password two-factor');

    expect($search()->limit(1)->get())->toHaveCount(1)
        ->and($search()->minScore(1.0)->get())->toBeEmpty()
        ->and($search()->minScore(-1.0)->get())->toHaveCount(3);
});

it('returns nothing for a blank query without calling the embedder', function () {
    $embedder = app(Embedder::class);
    $before = count($embedder->calls());

    expect(SemanticSearch::for(Article::class)->query('   ')->get())->toBeEmpty()
        ->and($embedder->calls())->toHaveCount($before);
});

it('skips models that were deleted after indexing', function () {
    $this->shipping->delete();

    $ids = SemanticSearch::for(Article::class)->query('international shipping')->get()
        ->map(fn (SearchResult $r) => $r->model->getKey());

    expect($ids)->not->toContain($this->shipping->getKey());
});

it('replaces old chunks when a model is re-indexed', function () {
    $this->shipping->update(['title' => 'Gift cards', 'body' => 'Gift cards never expire and can be used online.']);
    SemanticSearch::index($this->shipping);

    $top = SemanticSearch::for(Article::class)->query('gift cards expire')->get()->first();

    expect($top->model->is($this->shipping))->toBeTrue()
        ->and($top->citation->text)->toContain('Gift cards')
        ->and(app(VectorStore::class)->count($this->shipping->getMorphClass(), (string) $this->shipping->getKey()))->toBe(1);
});

it('stores several overlapping chunks for long text', function () {
    $long = Article::create(['title' => 'Long', 'body' => str_repeat('Lorem ipsum dolor sit amet consectetur. ', 20)]);

    expect(SemanticSearch::index($long))->toBeGreaterThan(1);
});

it('removes a model from the index', function () {
    SemanticSearch::remove($this->returns);

    $ids = SemanticSearch::for(Article::class)->query('refunds')->minScore(-1.0)->get()
        ->map(fn (SearchResult $r) => $r->model->getKey());

    expect($ids)->not->toContain($this->returns->getKey())->toHaveCount(2);
});

it('exposes search helpers on the model through the trait', function () {
    expect(Article::semanticSearch('forgotten password')->limit(1)->get()->first()->model->is($this->account))->toBeTrue();

    $this->account->semanticRemove();
    expect(app(VectorStore::class)->count($this->account->getMorphClass(), (string) $this->account->getKey()))->toBe(0);

    expect($this->account->semanticIndex())->toBeGreaterThan(0);
});

it('serialises results to arrays', function () {
    $array = SemanticSearch::for(Article::class)->query('returns')->limit(1)->get()->first()->toArray();

    expect($array)->toHaveKeys(['model_type', 'model_id', 'score', 'citation'])
        ->and($array['citation'])->toHaveKeys(['text', 'start', 'end', 'chunk_index']);
});

it('rejects models that are not searchable', function () {
    SemanticSearch::for(Note::class);
})->throws(InvalidArgumentException::class, 'must be an Eloquent model');

it('rejects invalid limits and scores', function (Closure $configure) {
    $configure(SemanticSearch::for(Article::class));
})->with([
    'zero limit' => fn ($search) => $search->limit(0),
    'score above 1' => fn ($search) => $search->minScore(1.5),
    'score below -1' => fn ($search) => $search->minScore(-2.0),
])->throws(InvalidArgumentException::class);
