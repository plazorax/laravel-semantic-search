<?php

declare(strict_types=1);

return [

    /*
    | Embedder: "openai" (any OpenAI-compatible /embeddings endpoint), "fake"
    | (deterministic, offline; for tests), or the class name of your own
    | Plazorax\SemanticSearch\Contracts\Embedder implementation.
    */
    'embedder' => env('SEMANTIC_SEARCH_EMBEDDER', 'openai'),

    /*
    | Vector length. Must match the embedding model and, for pgvector, the
    | column created by the published migration. Changing it requires a new
    | migration and a full re-index.
    */
    'dimensions' => (int) env('SEMANTIC_SEARCH_DIMENSIONS', 1536),

    'embedders' => [
        'openai' => [
            'base_url' => env('SEMANTIC_SEARCH_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('SEMANTIC_SEARCH_API_KEY', env('OPENAI_API_KEY')),
            'model' => env('SEMANTIC_SEARCH_MODEL', 'text-embedding-3-small'),
            // Send "dimensions" in the request body. Supported by OpenAI's
            // text-embedding-3 models; disable for servers that reject it.
            'send_dimensions' => (bool) env('SEMANTIC_SEARCH_SEND_DIMENSIONS', true),
            'batch_size' => 64,
            'timeout' => 30,
            'retries' => 2,
        ],
    ],

    /*
    | Vector store: "pgvector", "memory" (per-process, not persisted), or the
    | class name of your own Plazorax\SemanticSearch\Contracts\VectorStore.
    */
    'store' => env('SEMANTIC_SEARCH_STORE', 'pgvector'),

    'stores' => [
        'pgvector' => [
            // null uses the default database connection.
            'connection' => env('SEMANTIC_SEARCH_DB_CONNECTION'),
            'table' => 'semantic_search_chunks',
            // Nearest chunks fetched per requested result before keeping the
            // best chunk per model. Raise it if long documents crowd out others.
            'candidate_multiplier' => 4,
            // pgvector 0.8+ iterative index scans, so the model-type filter
            // does not starve HNSW results: "relaxed_order", "strict_order",
            // "off", or null to skip the setting entirely (pgvector < 0.8).
            'iterative_scan' => env('SEMANTIC_SEARCH_ITERATIVE_SCAN', 'relaxed_order'),
        ],
    ],

    /*
    | Chunking, in characters. Each chunk is at most "size" characters and
    | repeats up to "overlap" characters of the previous chunk.
    */
    'chunking' => [
        'size' => 1000,
        'overlap' => 200,
    ],

    /*
    | Queue used by `semantic-search:index --queue`. null = application default.
    */
    'queue' => [
        'connection' => env('SEMANTIC_SEARCH_QUEUE_CONNECTION'),
        'queue' => env('SEMANTIC_SEARCH_QUEUE'),
    ],

];
