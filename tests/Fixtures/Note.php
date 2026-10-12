<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that does not implement Searchable.
 */
class Note extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
