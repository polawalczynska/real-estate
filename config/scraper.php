<?php

declare(strict_types=1);

/**
 * Scraper and provider configuration.
 *
 * Centralises all timeouts, delays, limits, and browser-header
 * settings so they can be tuned per-environment without touching code.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Otodom Provider
    |--------------------------------------------------------------------------
    */
    'otodom' => [
        'base_url'         => env('OTODOM_BASE_URL', 'https://www.otodom.pl'),
        'search_path'      => '/pl/wyniki/sprzedaz/mieszkanie',
        'max_pages'        => (int) env('OTODOM_MAX_PAGES', 5),
        'page_delay'       => (int) env('OTODOM_PAGE_DELAY', 1),
        'offer_delay'      => (int) env('OTODOM_OFFER_DELAY', 1),
        'request_timeout'  => (int) env('OTODOM_REQUEST_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Browser Headers
    |--------------------------------------------------------------------------
    |
    | Shared browser-like headers used across scraper and image services
    | to avoid CDN blocks and bot detection.
    |
    */
    'browser_headers' => [
        'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'accept_html'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'accept_image'    => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
        'accept_language' => 'pl-PL,pl;q=0.9,en-US;q=0.8,en;q=0.7',
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Downloads
    |--------------------------------------------------------------------------
    */
    'images' => [
        'head_timeout'       => (int) env('IMAGE_HEAD_TIMEOUT', 10),
        'download_timeout'   => (int) env('IMAGE_DOWNLOAD_TIMEOUT', 30),
        'min_body_bytes'     => 1_000,
        'max_gallery_images' => 8,
        'max_fallback_images' => 5,
        'accepted_mimes'     => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/jpg',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Import Command
    |--------------------------------------------------------------------------
    */
    'import' => [
        'chunk_size'   => 5,
        'memory_limit' => '256M',
    ],

];
