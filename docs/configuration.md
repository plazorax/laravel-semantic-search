# Configuration

Publish the config file with:

```bash
php artisan vendor:publish --tag=semantic-search-config
```

Minimal `.env` for OpenAI:

```dotenv
SEMANTIC_SEARCH_API_KEY=sk-...            # falls back to OPENAI_API_KEY
SEMANTIC_SEARCH_MODEL=text-embedding-3-small
SEMANTIC_SEARCH_DIMENSIONS=1536           # must match the model and the migration
```

## Options

All keys live in `config/semantic-search.php`.

| Key | Env | Default | Notes |
| --- | --- | --- | --- |
| `embedder` | `SEMANTIC_SEARCH_EMBEDDER` | `openai` | `openai`, `fake`, or a class implementing `Contracts\Embedder` |
| `dimensions` | `SEMANTIC_SEARCH_DIMENSIONS` | `1536` | Must match the embedding model and the pgvector column. Changing it requires a new migration and a full re-index. |
| `embedders.openai.base_url` | `SEMANTIC_SEARCH_BASE_URL` | `https://api.openai.com/v1` | Any OpenAI-compatible endpoint |
| `embedders.openai.api_key` | `SEMANTIC_SEARCH_API_KEY` | `OPENAI_API_KEY` | Sent as a bearer token when set |
| `embedders.openai.model` | `SEMANTIC_SEARCH_MODEL` | `text-embedding-3-small` | |
| `embedders.openai.send_dimensions` | `SEMANTIC_SEARCH_SEND_DIMENSIONS` | `true` | Sends `dimensions` in the request body; disable for servers that reject it |
| `embedders.openai.batch_size` | | `64` | Inputs per request |
| `embedders.openai.timeout` | | `30` | Seconds |
| `embedders.openai.retries` | | `2` | Retries for connection errors, HTTP 429 and 5xx |
| `store` | `SEMANTIC_SEARCH_STORE` | `pgvector` | `pgvector`, `memory` (per process, not persisted), or a class implementing `Contracts\VectorStore` |
| `stores.pgvector.connection` | `SEMANTIC_SEARCH_DB_CONNECTION` | `null` | `null` uses the default database connection |
| `stores.pgvector.table` | | `semantic_search_chunks` | |
| `stores.pgvector.candidate_multiplier` | | `4` | Nearest chunks fetched per requested result before keeping the best chunk per model. Raise it if long documents crowd out others. |
| `stores.pgvector.iterative_scan` | `SEMANTIC_SEARCH_ITERATIVE_SCAN` | `relaxed_order` | `relaxed_order`, `strict_order`, `off`, or `null` to skip the setting (pgvector < 0.8). See [pgvector notes](pgvector.md). |
| `chunking.size` | | `1000` | Maximum characters per chunk |
| `chunking.overlap` | | `200` | Characters repeated from the previous chunk; must be smaller than `size` |
| `queue.connection` | `SEMANTIC_SEARCH_QUEUE_CONNECTION` | `null` | Used by `semantic-search:index --queue`; `null` = application default |
| `queue.queue` | `SEMANTIC_SEARCH_QUEUE` | `null` | |

## Other embedding providers

Any server that implements the OpenAI `POST /embeddings` request and response
shape works. Set the base URL and model, and turn off `send_dimensions` if the
server rejects the `dimensions` field:

```dotenv
SEMANTIC_SEARCH_BASE_URL=http://localhost:11434/v1
SEMANTIC_SEARCH_MODEL=nomic-embed-text
SEMANTIC_SEARCH_DIMENSIONS=768
SEMANTIC_SEARCH_SEND_DIMENSIONS=false
```

## Custom drivers

You can also set `embedder` or `store` in `config/semantic-search.php` to the
class name of your own `Embedder` or `VectorStore` implementation; it is
resolved from the container. An unknown name, or a class that does not
implement the contract, throws an `InvalidArgumentException`.
