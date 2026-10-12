<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Search;

use Illuminate\Database\Eloquent\Model;
use Plazorax\SemanticSearch\Chunking\Chunk;

/**
 * One model returned by a search, with the chunk that matched it.
 *
 * @template-covariant TModel of Model
 */
final readonly class SearchResult
{
    /**
     * @param  TModel  $model
     * @param  Chunk  $citation  The best-matching chunk of the model's searchable text.
     */
    public function __construct(
        public Model $model,
        public float $score,
        public Chunk $citation,
    ) {}

    /**
     * @return array{model_type: string, model_id: mixed, score: float, citation: array{text: string, start: int, end: int, chunk_index: int}}
     */
    public function toArray(): array
    {
        return [
            'model_type' => $this->model->getMorphClass(),
            'model_id' => $this->model->getKey(),
            'score' => $this->score,
            'citation' => [
                'text' => $this->citation->text,
                'start' => $this->citation->start,
                'end' => $this->citation->end,
                'chunk_index' => $this->citation->index,
            ],
        ];
    }
}
