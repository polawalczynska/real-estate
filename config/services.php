<?php

declare(strict_types=1);

/**
 * Third-party service credentials and tuning parameters.
 *
 * All sensitive values (API keys, proxy settings) MUST come from .env.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Anthropic (Claude) API
    |--------------------------------------------------------------------------
    */
    'anthropic' => [
        'api_key'       => env('ANTHROPIC_API_KEY'),
        'model'         => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
        'search_model'  => env('ANTHROPIC_SEARCH_MODEL', 'claude-sonnet-4-5-20250929'),
        'fallback_model' => env('ANTHROPIC_FALLBACK_MODEL', 'claude-sonnet-4-5-20250929'),
        'api_url'       => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),
        'api_version'   => '2023-06-01',

        // Normalization service
        'normalization_timeout'    => (int) env('AI_NORMALIZATION_TIMEOUT', 30),
        'normalization_max_tokens' => (int) env('AI_NORMALIZATION_MAX_TOKENS', 4096),

        // Search / Concierge service
        'search_timeout'           => (int) env('AI_SEARCH_TIMEOUT', 15),
        'search_max_tokens'        => (int) env('AI_SEARCH_MAX_TOKENS', 512),
        'converse_max_tokens'      => (int) env('AI_CONVERSE_MAX_TOKENS', 1024),
        'search_cache_ttl_hours'   => (int) env('AI_SEARCH_CACHE_TTL', 24),
    ],

];
