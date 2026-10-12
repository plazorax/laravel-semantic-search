# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-13

### Added

- `Embedder` contract with an OpenAI-compatible HTTP driver (configurable base
  URL, model, dimensions, batch size, timeout, and retries for connection
  errors, HTTP 429 and 5xx) and a deterministic
  `FakeEmbedder` for tests.
- `VectorStore` contract with a `PgvectorStore` (publishable migration with a
  `vector(n)` column and an HNSW cosine index; per-query `hnsw.ef_search` and
  `hnsw.iterative_scan` so filtered searches fill the limit) and an
  `InMemoryStore`.
- `Searchable` contract and `HasSemanticSearch` trait for Eloquent models.
- `TextChunker`: character-bounded, overlapping chunks that break on
  whitespace and record character offsets.
- Query API: `SemanticSearch::for(Model::class)->query()->limit()->minScore()->get()`
  returning the model, a cosine score and the matched chunk as a citation.
- `semantic-search:index {model} {--chunk=} {--queue} {--fresh}` command and
  the queued `IndexModel` job.
- Config file and auto-discovered service provider and facade.
