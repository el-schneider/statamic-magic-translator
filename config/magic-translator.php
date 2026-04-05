<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Exclude Blueprints
    |--------------------------------------------------------------------------
    |
    | Blueprint patterns (dot notation: "collection.blueprint") to exclude from
    | automatic fieldtype injection and action visibility.
    |
    | Supports wildcard patterns, e.g. "pages.*" to exclude an entire collection.
    |
    */

    'exclude_blueprints' => [],

    /*
    |--------------------------------------------------------------------------
    | Translation Service
    |--------------------------------------------------------------------------
    |
    | Which translation service to use. Supported: "prism", "deepl"
    |
    */

    'service' => env('CONTENT_TRANSLATOR_SERVICE', 'prism'),

    /*
    |--------------------------------------------------------------------------
    | Max Units Per Request
    |--------------------------------------------------------------------------
    |
    | Maximum number of translation units to send in a single API request.
    | Set to null to send all units in one request (default).
    | DeepL has a hard limit of 50 texts per request.
    |
    */

    'max_units_per_request' => env('CONTENT_TRANSLATOR_MAX_UNITS_PER_REQUEST'),

    /*
    |--------------------------------------------------------------------------
    | Log Completions
    |--------------------------------------------------------------------------
    |
    | Whether to log translation completions (useful for debugging prompts
    | and monitoring API usage).
    |
    */

    'log_completions' => env('CONTENT_TRANSLATOR_LOG_COMPLETIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The queue connection and name to use for translation jobs.
    |
    */

    'queue' => [
        'connection' => env('CONTENT_TRANSLATOR_QUEUE_CONNECTION', null),
        'name' => env('CONTENT_TRANSLATOR_QUEUE_NAME', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prism Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Prism (LLM) translation service.
    |
    */

    'prism' => [
        'provider' => env('CONTENT_TRANSLATOR_PROVIDER', 'openai'),
        'model' => env('CONTENT_TRANSLATOR_MODEL', 'gpt-5-mini'),
        // Long-form translation requests often exceed generic API defaults.
        // Keep these transport limits addon-scoped so translation reliability
        // does not depend on global Prism settings.
        'request_timeout' => env('CONTENT_TRANSLATOR_PRISM_REQUEST_TIMEOUT', 120),
        'connect_timeout' => env('CONTENT_TRANSLATOR_PRISM_CONNECT_TIMEOUT', 15),
        'retry_attempts' => env('CONTENT_TRANSLATOR_PRISM_RETRY_ATTEMPTS', 1),
        'retry_sleep_ms' => env('CONTENT_TRANSLATOR_PRISM_RETRY_SLEEP_MS', 1000),
        'prompts' => [
            'system' => 'magic-translator::prompts.system',
            'user' => 'magic-translator::prompts.user',
            'overrides' => [
                // 'ja' => ['system' => 'magic-translator::prompts.system-ja'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DeepL Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the DeepL translation service.
    |
    */

    'deepl' => [
        'api_key' => env('DEEPL_API_KEY'),
        'formality' => 'default',
        'overrides' => [
            // 'de' => ['formality' => 'prefer_more'],
            // 'ja' => ['formality' => 'prefer_more'],
        ],
    ],

];
