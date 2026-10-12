<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Facades;

use Illuminate\Support\Facades\Facade;
use Plazorax\SemanticSearch\SemanticSearchManager;

/**
 * @method static \Plazorax\SemanticSearch\Search\PendingSearch<TModel> for<TModel of \Illuminate\Database\Eloquent\Model&\Plazorax\SemanticSearch\Contracts\Searchable>(class-string<TModel> $modelClass)
 * @method static int index(\Illuminate\Database\Eloquent\Model&\Plazorax\SemanticSearch\Contracts\Searchable $model)
 * @method static void remove(\Illuminate\Database\Eloquent\Model $model)
 * @method static void flush(string $modelClass)
 *
 * @see SemanticSearchManager
 */
final class SemanticSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SemanticSearchManager::class;
    }
}
