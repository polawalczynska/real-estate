<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\ListingProviderInterface;
use App\Services\Ai\HtmlExtractorService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scrapes raw listing HTML from Otodom.pl.
 *
 * This provider ONLY handles data collection — no AI processing.
 * Returns raw HTML and DOM-extracted metadata for downstream jobs.
 *
 * Flow:
 *  1. Paginate through search results pages to collect offer URLs.
 *  2. Fetch each offer's full HTML.
 *  3. Extract basic metadata (title, images) via DOM — no AI.
 *  4. Return raw data for skeleton creation and parallel job dispatch.
 */
final class OtodomProvider implements ListingProviderInterface
{
    private const MAX_PAGES              = 5;
    private const PAGE_DELAY_SECONDS     = 1;
    private const OFFER_DELAY_SECONDS    = 1;
    private const REQUEST_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly HtmlExtractorService $htmlExtractor,
        private readonly string $baseUrl = 'https://www.otodom.pl',
    ) {}

    public function fetch(int $limit = 10): array
    {
        try {
            $offerUrls = $this->collectOfferUrls($limit);

            if ($offerUrls === []) {
                Log::warning('OtodomProvider: No offer URLs found');
                return [];
            }

            return $this->scrapeOffers($offerUrls);
        } catch (Throwable $e) {
            $level = $this->isNetworkError($e) ? 'notice' : 'warning';
            Log::log($level, 'OtodomProvider: Fetch failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    private function collectOfferUrls(int $limit): array
    {
        $baseSearchUrl = $this->baseUrl . '/pl/wyniki/sprzedaz/mieszkanie';
        $offerUrls     = [];
        $page          = 1;

        while (count($offerUrls) < $limit && $page <= self::MAX_PAGES) {
            $searchUrl = $page === 1
                ? $baseSearchUrl
                : $baseSearchUrl . '?page=' . $page;

            $response = $this->request($searchUrl);

            if (! $response->successful()) {
                if ($page === 1) {
                    return [];
                }
                break;
            }

            $body = $response->body();
            unset($response);
            $pageUrls = $this->extractOfferUrls($body, $limit - count($offerUrls));
            unset($body);

            if ($pageUrls === []) {
                break;
            }

            foreach ($pageUrls as $url) {
                if (! in_array($url, $offerUrls, true)) {
                    $offerUrls[] = $url;
                    if (count($offerUrls) >= $limit) {
                        break 2;
                    }
                }
            }

            $page++;

            if ($page <= self::MAX_PAGES && count($offerUrls) < $limit) {
                sleep(self::PAGE_DELAY_SECONDS);
            }
        }

        return array_slice($offerUrls, 0, $limit);
    }

    private function scrapeOffers(array $offerUrls): array
    {
        $results = [];

        foreach ($offerUrls as $offerUrl) {
            try {
                $response = $this->request($offerUrl);

                if (! $response->successful()) {
                    Log::warning('OtodomProvider: Offer page failed', [
                        'url'    => $offerUrl,
                        'status' => $response->status(),
                    ]);
                    continue;
                }

                $html = $response->body();
                unset($response);

                $this->htmlExtractor->extractContent($html);
                $images = $this->htmlExtractor->getLastExtractedImages();
                $title  = $this->htmlExtractor->extractTitle($html);

                $results[] = [
                    'external_id'      => 'otodom_' . md5($offerUrl),
                    'title'            => $title,
                    'url'              => $offerUrl,
                    'raw_html'         => $html,
                    'extracted_images' => $images,
                    'scraped_at'       => now()->toIso8601String(),
                ];

                unset($html);

                if (count($results) < count($offerUrls)) {
                    sleep(self::OFFER_DELAY_SECONDS);
                }
            } catch (Throwable $e) {
                Log::error('OtodomProvider: Scrape failed', [
                    'url'   => $offerUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('OtodomProvider: Scraped offers', ['count' => count($results)]);

        return $results;
    }

    private function extractOfferUrls(string $html, int $limit): array
    {
        $urls = [];

        if (preg_match_all('/<a[^>]*href=["\']([^"\']*\/oferta\/[^"\']*)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $url = $this->normalizeUrl($url);
                if ($url !== null && ! in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
                if (count($urls) >= $limit) {
                    return $urls;
                }
            }
        }

        if (count($urls) < $limit && preg_match_all('/data-cy="listing-item"[^>]*>.*?<a[^>]*href=["\']([^"\']*oferta[^"\']*)["\']/is', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $url = $this->normalizeUrl($url);
                if ($url !== null && ! in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
                if (count($urls) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($urls, 0, $limit);
    }

    private function request(string $url): \Illuminate\Http\Client\Response
    {
        return Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withHeaders([
                'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language'           => 'pl-PL,pl;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding'           => 'gzip, deflate, br',
                'Connection'                => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Referer'                   => $this->baseUrl,
            ])
            ->get($url);
    }

    private function normalizeUrl(string $url): ?string
    {
        if (! str_starts_with($url, 'http')) {
            $url = str_starts_with($url, '/')
                ? $this->baseUrl . $url
                : $this->baseUrl . '/' . $url;
        }

        return strtok($url, '?#') ?: null;
    }

    private function isNetworkError(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Could not resolve host')
            || str_contains($e->getMessage(), 'cURL error 6')
            || str_contains($e->getMessage(), 'Connection refused')
            || str_contains($e->getMessage(), 'Network is unreachable');
    }
}
