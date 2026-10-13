# Limitations and roadmap

## Limitations in v0.1

- **One result per model, but candidates are limited.** `PgvectorStore`
  de-duplicates in PHP after fetching `limit × candidate_multiplier` chunks.
  If one long document has many near-identical chunks, fewer than `limit`
  models can come back. Raise `candidate_multiplier` if this happens.
- **Approximate search.** HNSW trades recall for speed. Iterative scans stop
  after `hnsw.max_scan_tuples` (pgvector default 20,000), so a model type that
  is a tiny fraction of a very large table can still get fewer results. See
  [pgvector notes](pgvector.md).
- **No filtering by attributes** (tenant, status, locale) inside the vector
  query. Global scopes are applied when models are loaded, which can also
  reduce the result count.
- **Character-based chunking**, not token-based. Keep `chunking.size` well
  under your embedding model's input limit.
- **No automatic sync** on model events and no change detection; re-indexing
  always re-embeds.
- **Dimensions are fixed** by the migration. pgvector's HNSW index supports up
  to 2,000 dimensions for the `vector` type; larger models need a different
  column type or index.
- `InMemoryStore` is per process and not persisted. `FakeEmbedder` is for tests
  only.
- Laravel 11 is past its security-support window; CI still checks
  compatibility with it, but new projects should use Laravel 12.

## Possible next steps

- Opt-in observer to index on save and remove on delete.
- Metadata filters stored with each chunk.
- Hybrid ranking (full-text + vector).
- Skip re-embedding unchanged chunks (content hash).
- More stores (SQLite with sqlite-vec, Qdrant).
