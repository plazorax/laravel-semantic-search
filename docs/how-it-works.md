# How it works

1. **Chunking.** `toSearchableText()` is split by `TextChunker` into chunks of
   at most `chunking.size` characters (default 1000), each repeating up to
   `chunking.overlap` characters (default 200) of the previous one. Chunks end
   on whitespace when that keeps them at least half the target size, and start
   on a word boundary. Offsets are in characters (multibyte-safe), so
   `mb_substr($text, $start, $end - $start)` returns the chunk text.
2. **Embedding.** Chunks are sent in batches (`batch_size`, default 64) to the
   embeddings endpoint. The response is validated: one vector per input, each
   with the configured number of dimensions. Connection errors, HTTP 429 and
   5xx responses are retried (`retries`, default 2); other errors fail at once
   with an `EmbeddingException`.
3. **Storing.** Re-indexing a model replaces all of its chunks in one
   transaction. Rows are keyed by the model's morph class and primary key, so
   morph maps are respected. A model whose searchable text is empty has its
   chunks removed.
4. **Searching.** The query is embedded and the store returns the best chunk
   per model. `PgvectorStore` orders by the cosine distance operator `<=>`
   (served by the HNSW index from the migration), takes
   `limit × candidate_multiplier` nearest chunks, and keeps the best one per
   model. Score is `1 - cosine distance`. The HNSW settings applied to each
   search are described in [pgvector notes](pgvector.md). `InMemoryStore`
   scores every chunk exactly. Models are then loaded with one `whereKey()`
   query.

## Sources

Conventions used:

- [OpenAI embeddings API reference](https://platform.openai.com/docs/api-reference/embeddings/create)
- [pgvector README](https://github.com/pgvector/pgvector) (operators, HNSW, filtering, iterative index scans, limits)
- [Laravel package development](https://laravel.com/docs/12.x/packages)
- [Laravel HTTP client](https://laravel.com/docs/12.x/http-client)
- [Laravel queues](https://laravel.com/docs/12.x/queues)
