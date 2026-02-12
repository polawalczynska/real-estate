<?php

declare(strict_types=1);

return [

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
        'search_model' => env('ANTHROPIC_SEARCH_MODEL', 'claude-sonnet-4-5-20250929'),
    ],

];
