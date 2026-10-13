# pgvector notes

## Requirements

The `pgvector` store needs PostgreSQL with the
[pgvector](https://github.com/pgvector/pgvector) extension, version 0.8.0 or
later. On older versions set `SEMANTIC_SEARCH_ITERATIVE_SCAN=null` so the
store does not try to set `hnsw.iterative_scan`.

## Migration

```bash
php artisan vendor:publish --tag=semantic-search-migrations
php artisan migrate
```

The migration runs `CREATE EXTENSION IF NOT EXISTS vector`, so the database
role needs permission to create extensions, or the extension must already be
enabled. It creates the `semantic_search_chunks` table (configurable via
`stores.pgvector.table`, on `stores.pgvector.connection`) with a
`vector(n)` column, where `n` is `semantic-search.dimensions` at migration
time, and an HNSW index using `vector_cosine_ops`.

Dimensions are therefore fixed by the migration. pgvector's HNSW index
supports up to 2,000 dimensions for the `vector` type; larger models need a
different column type or index.

## Filtered HNSW searches

All model types share one table, and each search filters on `model_type`.
HNSW applies that filter after the index scan, and a scan returns at most
`hnsw.ef_search` rows (default 40), so a model type with few rows in a shared
table could get too few results. Each search therefore runs in a transaction
that uses `SET LOCAL` to set:

- `hnsw.ef_search` to the candidate count (`limit × candidate_multiplier`),
  clamped to 40–1000;
- `hnsw.iterative_scan` (pgvector 0.8+) to `stores.pgvector.iterative_scan`,
  default `relaxed_order`. Set it to `null` to skip this setting.

With `relaxed_order`, rows can come back slightly out of distance order, so
the store compares scores rather than keeping the first row per model. The
integration tests cover the filtered case.

Iterative scans stop after `hnsw.max_scan_tuples` (pgvector default 20,000),
so a model type that is a tiny fraction of a very large table can still get
fewer results. See [limitations](limitations.md).
