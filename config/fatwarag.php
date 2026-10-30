<?php

return [
    /*
     * Where the raw harvest lives (data/raw/<source>/ at the repository root). Importers read only from here.
     */
    'raw_path' => env('FATWARAG_RAW_PATH', base_path('../data/raw')),

    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'models' => [
            'summary' => env('MODEL_SUMMARY', 'anthropic/claude-sonnet-5.5'),
            'translation' => env('MODEL_TRANSLATION', 'anthropic/claude-haiku-5.5'),
            'embedding' => 'voyageai/voyage-4-large', // locked: changing it means re-embedding everything
            'rerank' => env('MODEL_RERANK', 'voyageai/rerank-3'),
        ],
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET_KEY'),
    ],

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
    ],

    /*
     * Defaults; the admin can change the daily cap, the budget and the per-visitor question and summary limits (settings table).
     */
    'limits' => [
        'daily_questions' => 1000,
        'monthly_budget_usd' => 100,
        'hourly_questions_per_visitor' => 20,
        'hourly_translations_per_visitor' => 10,
        // Paid summaries only: a stored one (common question, or already written for that answer and language) is free.
        'hourly_summaries_per_visitor' => 20,
    ],

    'admin_url' => env('ADMIN_URL', env('APP_URL').'/admin'),
    'site_url' => env('SITE_URL', 'http://localhost:3100'),

    'bff_key' => env('BFF_KEY'),

    /*
     * Asking questions: limiter counters and the short-lived answer handoff live in this cache store (Redis).
     */
    /*
     * Search: how many fatwas an answer lists at most, after re-ranking (the summary reads all of them, in full).
     */
    'search' => [
        'max_results' => (int) env('SEARCH_MAX_RESULTS', 5),
        // A question asking several things (QuestionParts) is searched part by part: up to this many per part, merged
        // best-first across parts without repeats, at most `max_results_parts` in all.
        'max_results_per_part' => (int) env('SEARCH_MAX_RESULTS_PER_PART', 5),
        'max_results_parts' => (int) env('SEARCH_MAX_RESULTS_PARTS', 10),
    ],

    'ask' => [
        'cache_store' => env('ASK_CACHE_STORE', 'redis'),
        'relevance_floor' => (float) env('SEARCH_RELEVANCE_FLOOR', 0.5),
        // The off-topic gate (QuestionGate): the model's timeout, the longest the search waits for it (the step's
        // process needs a moment to start), and how long a verdict is cached. A gate that can't answer lets the question through.
        'gate_timeout_seconds' => (int) env('QUESTION_GATE_TIMEOUT_SECONDS', 3),
        'gate_wait_seconds' => (float) env('QUESTION_GATE_WAIT_SECONDS', 5),
        'gate_cache_seconds' => (int) env('QUESTION_GATE_CACHE_SECONDS', 30 * 86400),
        // Summary safeguard: share of a point's content words found in its support quotes or cited fatwas, and in its
        // support quotes alone. Calibrated on real summaries.
        'grounding_min_coverage' => (float) env('SUMMARY_GROUNDING_MIN_COVERAGE', 0.7),
        'grounding_min_support_coverage' => (float) env('SUMMARY_GROUNDING_MIN_SUPPORT_COVERAGE', 0.4),
    ],

    /*
     * The answer summary. Its input is every listed fatwa in full, never trimmed (owner rule), plus the fatwas
     * they refer to (at most two per fatwa), also in full. Only a request past `max_input_tokens` leaves out whole
     * cross-references, then whole lower-ranked fatwas (logged). A rewrite after a failed draft is only tried while the whole summary is within its time budget;
     * otherwise the page shows the fatwa list alone. The draft is streamed and each item is judged (and, for English,
     * translated) as soon as it is written, side by side in background processes (`process`, or `sync` to run them
     * one after another).
     */
    'summary' => [
        // The summary model's context, less room for its output: the request's estimated input tokens stay under it.
        'max_input_tokens' => (int) env('SUMMARY_MAX_INPUT_TOKENS', 150000),
        'max_output_tokens' => (int) env('SUMMARY_MAX_OUTPUT_TOKENS', 6000),
        'rewrite_budget_seconds' => (float) env('SUMMARY_REWRITE_BUDGET_SECONDS', 25),
        'concurrency_driver' => env('SUMMARY_CONCURRENCY_DRIVER', 'process'),
        // The summary model's own reasoning before it writes: low, medium, high, default (the provider's) or off (where the model allows it).
        // Every item is checked by the safeguard and the judge either way; reasoning only costs time and tokens.
        'reasoning' => env('SUMMARY_REASONING', 'low'),
        // Owner decision: the Haiku claims judge for points, preferences, cautions and the agree
        // headline is on by default. Set false and items are shown once they pass every lexical check (which reject
        // the known faithfulness attacks on their own). The English translation judge stays on either way.
        'claims_judge' => (bool) env('SUMMARY_CLAIMS_JUDGE', true),
        // Pruning the sentences the claims judge names, then re-judging the rest for what it left out (PruneJudge). Off
        // for v1 (owner ruling): any failing sentence fails its item whole, and PruneJudge is never called.
        'prune' => (bool) env('SUMMARY_PRUNE', false),
        // Step processes alive at once server-wide, waiting or working (max_spawned); of those, judge and translation
        // steps working at once across the whole server and for one visitor (each summary also has at most 6). Beyond it a step waits up to queue_seconds in a
        // first-come queue, then runs in the request process. Leases are kept in this Redis connection.
        'max_spawned' => (int) env('SUMMARY_MAX_SPAWNED', 24),
        'max_processes' => (int) env('SUMMARY_MAX_PROCESSES', 18),
        'max_processes_per_client' => (int) env('SUMMARY_MAX_PROCESSES_PER_CLIENT', 6),
        'queue_seconds' => (float) env('SUMMARY_QUEUE_SECONDS', 3),
        // Summaries (JSON or stream) one visitor may start per minute.
        'per_client_per_minute' => (int) env('SUMMARY_PER_CLIENT_PER_MINUTE', 10),
        // Summaries (JSON or stream) one visitor may have running at once: each holds an Octane worker while it runs.
        'max_streams_per_client' => (int) env('SUMMARY_MAX_STREAMS_PER_CLIENT', 2),
        // A summary stops (failed) after this much wall time; PHP's time limit counts CPU time on Linux.
        'wall_seconds' => (float) env('SUMMARY_WALL_SECONDS', 300),
        'process_slots_connection' => env('SUMMARY_PROCESS_SLOTS_CONNECTION', 'default'),
        // The summary stream sends a `: ping` comment when it sent nothing for this long, so a reader who left is noticed.
        'heartbeat_seconds' => (float) env('SUMMARY_HEARTBEAT_SECONDS', 5),
    ],

    /*
     * OpenRouter list prices in USD per million tokens. The SDK doesn't return the billed cost, so spend is estimated.
     */
    'ai_prices' => [
        'summary' => ['input' => 2.0, 'output' => 10.0],      // anthropic/claude-sonnet-5.5
        'translation' => ['input' => 0.10, 'output' => 0.50], // anthropic/claude-haiku-5.5
        'embedding' => ['input' => 0.12, 'output' => 0.0],    // voyageai/voyage-4-large
        'rerank' => ['input' => 0.05, 'output' => 0.0],       // voyageai/rerank-3 (not listed by OpenRouter; Voyage's own price)
    ],

    /*
     * Content API: page sizes, the 1-day cache TTL and translation settings. Prices are USD per million tokens
     * for the translation model (an estimate; OpenRouter does not return the cost with the response).
     */
    'content' => [
        'sitemap_per_page' => 5000,
        'topic_per_page' => 20,
        'cache_ttl' => 86400,
        'cache_store' => env('CONTENT_CACHE_STORE'),
    ],

    /*
     * Shared topic tree: an islamqa leaf whose mapped categories (in these sources) have their own subcategories gets
     * those subcategories as child topics, when each has at least `enrich_min_fatwas` fatwas and at least two qualify.
     */
    'topics' => [
        'enrich_sources' => ['islamweb', 'binbaz'],
        'enrich_min_fatwas' => (int) env('TOPICS_ENRICH_MIN_FATWAS', 10),
    ],

    'translation' => [
        'batch_size' => 40,
        'body_timeout' => 25,
        'call_timeout' => 90,
        // OpenRouter's list price for anthropic/claude-haiku-5.5. The SDK does not expose the billed cost, so it is estimated.
        'pricing' => ['input_per_million' => 0.10, 'output_per_million' => 0.50],
    ],

    /*
     * 3-day refresh collectors (lane A): the pause between requests is the rate each source's study found safe, and
     * binbaz probing stops after this many consecutive missing ids.
     */
    'collectors' => [
        'user_agent' => 'FatwaRAG/0.1',
        'timeout' => 30,
        'delay_ms' => ['islamqa' => 500, 'binbaz' => 500, 'islamweb' => 333],
        'binbaz_miss_limit' => 50,
        'islamweb_listing_pages' => 10,
    ],
];
