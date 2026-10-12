<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Plazorax\SemanticSearch\Concerns\HasSemanticSearch;
use Plazorax\SemanticSearch\Contracts\Searchable;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 */
class Article extends Model implements Searchable
{
    use HasSemanticSearch;

    protected $guarded = [];

    public function toSearchableText(): string
    {
        return $this->title."\n\n".$this->body;
    }
}
