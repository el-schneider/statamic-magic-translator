<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | The collection handles that the Content Translator fieldtype should be
    | auto-injected into. Only entries in these collections will have the
    | translation UI available in the control panel.
    |
    */

    'collections' => [],

    /*
    |--------------------------------------------------------------------------
    | Exclude Blueprints
    |--------------------------------------------------------------------------
    |
    | Specific blueprints (in dot notation: "collection.blueprint") that should
    | be excluded from automatic fieldtype injection, even if their collection
    | is listed above.
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

    'max_units_per_request' => null,

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
        'provider' => env('CONTENT_TRANSLATOR_PROVIDER', 'anthropic'),
        'model' => env('CONTENT_TRANSLATOR_MODEL', 'claude-sonnet-4-20250514'),
        'prompts' => [
            'system' => 'content-translator::prompts.system',
            'user' => 'content-translator::prompts.user',
            'overrides' => [
                // 'ja' => ['system' => 'content-translator::prompts.system-ja'],
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
