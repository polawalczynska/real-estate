<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\ListingProviderInterface;
use App\Exceptions\ScraperException;
use App\Services\Ai\HtmlExtractorService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scrapes raw listing HTML from Otodom.pl and extracts structured
 * data via JSON-LD (Phase 1: Technical Extraction).
 *
 * All URLs, timeouts, and delays are read from `config/scraper.php`
 * so the provider can be tuned per-environment without code changes.
 *
 * Pipeline:
 *  1. Paginate search results to collect offer URLs.
 *  2. Fetch each offer's full HTML.
 *  3. Extract structured data from JSON-LD — deterministic, no AI.
 *  4. Return enriched payloads for skeleton creation + AI dispatch.
 */
final class OtodomProvider implements ListingProviderInterface
{
    private readonly string $baseUrl;

    private readonly string $searchPath;

    private readonly int $maxPages;

    private readonly int $pageDelay;

    private readonly int $offerDelay;

    private readonly int $requestTimeout;

    public function __construct(
        private readonly HtmlExtractorService $htmlExtractor,
    ) {
        $this->baseUrl        = config('scraper.otodom.base_url', 'https://www.otodom.pl');
        $this->searchPath     = config('scraper.otodom.search_path', '/pl/wyniki/sprzedaz/mieszkanie');
        $this->maxPages       = config('scraper.otodom.max_pages', 5);
        $this->pageDelay      = config('scraper.otodom.page_delay', 1);
        $this->offerDelay     = config('scraper.otodom.offer_delay', 1);
        $this->requestTimeout = config('scraper.otodom.request_timeout', 30);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ScraperException On unrecoverable network or parsing failures.
     */
    public function fetch(int $limit = 10): array
    {
        try {
            $offerUrls = $this->collectOfferUrls($limit);

            if ($offerUrls === []) {
                Log::warning('OtodomProvider: No offer URLs found on search pages.');

                return [];
            }

            return $this->scrapeOffers($offerUrls);
        } catch (ScraperException $e) {
            throw $e;
        } catch (Throwable $e) {
            $level = $this->isNetworkError($e) ? 'notice' : 'warning';
            Log::log($level, 'OtodomProvider: Fetch failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    // ─── Search-Page Crawling ─────────────────────────────────────

    /**
     * Paginate through search results and collect unique offer URLs.
     *
     * @return list<string> Unique offer URLs, up to $limit.
     */
    private function collectOfferUrls(int $limit): array
    {
        $baseSearchUrl = $this->baseUrl . $this->searchPath;
        $offerUrls     = [];
        $page          = 1;

        while (count($offerUrls) < $limit && $page <= $this->maxPages) {
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

            $body     = $response->body();
            $pageUrls = $this->extractOfferUrls($body, $limit - count($offerUrls));
            unset($body, $response);

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

            if ($page <= $this->maxPages && count($offerUrls) < $limit) {
                sleep($this->pageDelay);
            }
        }

        return array_slice($offerUrls, 0, $limit);
    }

    // ─── Offer Scraping ───────────────────────────────────────────

    /**
     * Fetch and extract structured data from each offer URL.
     *
     * @param  list<string>  $offerUrls
     * @return list<array<string, mixed>>
     */
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

                $jsonLd = $this->htmlExtractor->extractJsonLd($html);

                if ($jsonLd === null) {
                    Log::warning('OtodomProvider: No JSON-LD found, skipping offer', [
                        'url' => $offerUrl,
                    ]);
                    unset($html);
                    continue;
                }

                $results[] = [
                    'external_id'      => $jsonLd['external_id'] ?? ('otodom_' . md5($offerUrl)),
                    'title'            => $jsonLd['title'],
                    'url'              => $offerUrl,
                    'raw_html'         => $html,
                    'extracted_images' => $jsonLd['images'],
                    'json_ld'          => $jsonLd,
                    'scraped_at'       => now()->toIso8601String(),
                ];

                unset($html);

                if (count($results) < count($offerUrls)) {
                    sleep($this->offerDelay);
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

    // ─── URL Extraction ───────────────────────────────────────────

    /**
     * Extract offer URLs from a search-results HTML page.
     *
     * @return list<string>
     */
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

    // ─── HTTP ─────────────────────────────────────────────────────

    /**
     * Perform a browser-like HTTP GET request.
     */
    private function request(string $url): Response
    {
        $userAgent = config('scraper.browser_headers.user_agent');

        return Http::timeout($this->requestTimeout)
            ->withHeaders([
                'User-Agent'                => $userAgent,
                'Accept'                    => config('scraper.browser_headers.accept_html'),
                'Accept-Language'           => config('scraper.browser_headers.accept_language'),
                'Accept-Encoding'           => 'gzip, deflate, br',
                'Connection'                => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Referer'                   => $this->baseUrl,
            ])
            ->get($url);
    }

    /**
     * Normalise a relative or absolute URL to an absolute, query-free URL.
     */
    private function normalizeUrl(string $url): ?string
    {
        if (! str_starts_with($url, 'http')) {
            $url = str_starts_with($url, '/')
                ? $this->baseUrl . $url
                : $this->baseUrl . '/' . $url;
        }

        return strtok($url, '?#') ?: null;
    }

    /**
     * Determine whether an exception is a transient network error (DNS, connection refused).
     */
    private function isNetworkError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Could not resolve host')
            || str_contains($message, 'cURL error 6')
            || str_contains($message, 'Connection refused')
            || str_contains($message, 'Network is unreachable');
    }
}
