<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch;

use Illuminate\Database\Eloquent\Model;
use Plazorax\SemanticSearch\Chunking\TextChunker;
use Plazorax\SemanticSearch\Contracts\Embedder;
use Plazorax\SemanticSearch\Contracts\Searchable;
use Plazorax\SemanticSearch\Contracts\VectorStore;
use Plazorax\SemanticSearch\Exceptions\EmbeddingException;
use Plazorax\SemanticSearch\Stores\EmbeddedChunk;

/**
 * Chunks a model's searchable text, embeds the chunks and stores them.
 */
final readonly class Indexer
{
    public function __construct(
        private Embedder $embedder,
        private VectorStore $store,
        private TextChunker $chunker,
    ) {}

    /**
     * Re-index one model, replacing any chunks stored for it before.
     *
     * @return int The number of chunks stored.
     */
    public function index(Model&Searchable $model): int
    {
        $chunks = $this->chunker->chunk($model->toSearchableText());

        if ($chunks === []) {
            $this->remove($model);

            return 0;
        }

        $vectors = $this->embedder->embed(array_map(static fn ($chunk): string => $chunk->text, $chunks));

        if (count($vectors) !== count($chunks)) {
            throw new EmbeddingException(sprintf(
                'The embedder returned %d vectors for %d chunks.', count($vectors), count($chunks)
            ));
        }

        $embedded = array_map(
            static fn ($chunk, array $vector): EmbeddedChunk => new EmbeddedChunk($chunk, $vector),
            $chunks,
            $vectors,
        );

        $this->store->replace($model->getMorphClass(), self::key($model), $embedded);

        return count($embedded);
    }

    public function remove(Model $model): void
    {
        $this->store->delete($model->getMorphClass(), self::key($model));
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function flush(string $modelClass): void
    {
        $this->store->flush((new $modelClass)->getMorphClass());
    }

    public static function key(Model $model): string
    {
        $key = $model->getKey();

        if (! is_scalar($key)) {
            throw new \InvalidArgumentException('Only models with a scalar primary key can be indexed.');
        }

        return (string) $key;
    }
}
