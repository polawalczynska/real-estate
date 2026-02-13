<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ImageAttacherInterface;
use App\Models\Listing;
use App\Services\Concerns\ValidatesImageUrls;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Downloads external images and attaches them to listings via Spatie Media Library.
 *
 * Sends browser-like headers (from `config/scraper.php`) to bypass CDN blocks,
 * validates MIME types from both HTTP headers and file contents, and supports
 * AI-curated hero/gallery selection with automatic fallback.
 *
 * Attachment strategies (tried in order):
 *  A. AI-selected images (hero_url + gallery_urls).
 *  B. First N raw image URLs from DOM extraction.
 */
final class ListingImageService implements ImageAttacherInterface
{
    use ValidatesImageUrls;

    /**
     * {@inheritDoc}
     */
    public function attachImages(
        Listing $listing,
        ?array $selectedImages = null,
        array $fallbackUrls = [],
    ): array {
        if ($listing->getMedia('gallery')->isNotEmpty()) {
            return [
                'hero_attached' => $this->hasHero($listing),
                'gallery_count' => $listing->getMedia('gallery')->count(),
                'errors'        => [],
            ];
        }

        $errors       = [];
        $galleryCount = 0;
        $heroAttached = false;
        $maxGallery   = (int) config('scraper.images.max_gallery_images', 8);
        $maxFallback  = (int) config('scraper.images.max_fallback_images', 5);

        // Strategy A: AI-selected images (hero_url + gallery_urls)
        $hasAiSelection = ! empty($selectedImages['hero_url']) || ! empty($selectedImages['gallery_urls']);

        if ($hasAiSelection) {
            if (! empty($selectedImages['hero_url'])) {
                $result = $this->downloadAndAttach($listing, $selectedImages['hero_url'], isHero: true);
                if ($result['success']) {
                    $heroAttached = true;
                    $galleryCount++;
                } else {
                    $errors[] = "Hero failed: {$result['error']}";
                }
            }

            $heroUrl = $selectedImages['hero_url'] ?? '';
            foreach (array_slice($selectedImages['gallery_urls'] ?? [], 0, $maxGallery) as $i => $url) {
                if ($url === $heroUrl) {
                    continue;
                }
                $promoteToHero = ! $heroAttached && $galleryCount === 0;
                $result        = $this->downloadAndAttach($listing, $url, isHero: $promoteToHero);
                if ($result['success']) {
                    if ($promoteToHero && $result['is_hero']) {
                        $heroAttached = true;
                    }
                    $galleryCount++;
                } else {
                    $errors[] = "Gallery[{$i}] failed: {$result['error']}";
                }
            }
        }

        // Strategy B: Fallback (first N raw URLs)
        if ($galleryCount === 0 && ! empty($fallbackUrls)) {
            foreach (array_slice($fallbackUrls, 0, $maxFallback) as $i => $url) {
                $result = $this->downloadAndAttach($listing, $url, isHero: $i === 0);
                if ($result['success']) {
                    if ($i === 0) {
                        $heroAttached = true;
                    }
                    $galleryCount++;
                } else {
                    $errors[] = "Fallback[{$i}] failed: {$result['error']}";
                }
            }
        }

        if ($errors !== []) {
            Log::warning('ListingImageService: Some images failed', [
                'listing_id' => $listing->id,
                'strategy'   => $hasAiSelection ? 'ai_selected' : 'fallback',
                'attached'   => $galleryCount,
                'errors'     => array_slice($errors, 0, 3),
            ]);
        }

        return [
            'hero_attached' => $heroAttached,
            'gallery_count' => $galleryCount,
            'errors'        => $errors,
        ];
    }

    // ─── Download & Attach ─────────────────────────────────────────

    /**
     * Download an image from a URL and attach it to the listing's gallery.
     *
     * Performs a HEAD check first (MIME validation), then a full GET.
     * Validates the actual file MIME via finfo after download.
     *
     * @return array{success: bool, error?: string, media_id?: int, is_hero: bool}
     */
    private function downloadAndAttach(Listing $listing, string $url, bool $isHero = false): array
    {
        if (! $this->isValidImageUrl($url)) {
            return ['success' => false, 'error' => 'Invalid URL format', 'is_hero' => false];
        }

        $headTimeout     = (int) config('scraper.images.head_timeout', 10);
        $downloadTimeout = (int) config('scraper.images.download_timeout', 30);
        $minBodyBytes    = (int) config('scraper.images.min_body_bytes', 1_000);
        $acceptedMimes   = config('scraper.images.accepted_mimes', []);

        try {
            $headResponse = Http::timeout($headTimeout)->withHeaders($this->browserHeaders())->head($url);
            $statusCode   = $headResponse->status();
            $contentType  = $headResponse->header('Content-Type') ?? '';

            if ($statusCode < 400) {
                $mimeOk = false;
                foreach ($acceptedMimes as $mime) {
                    if (str_contains($contentType, $mime)) {
                        $mimeOk = true;
                        break;
                    }
                }
                if (! $mimeOk && $contentType !== '' && ! str_contains($contentType, 'octet-stream')) {
                    return ['success' => false, 'error' => "Non-image MIME: {$contentType}", 'is_hero' => false];
                }
            }

            $response  = Http::timeout($downloadTimeout)->withHeaders($this->browserHeaders())->get($url);
            $getStatus = $response->status();

            if ($getStatus >= 400) {
                return ['success' => false, 'error' => "HTTP {$getStatus}", 'is_hero' => false];
            }

            $body = $response->body();
            if (strlen($body) < $minBodyBytes) {
                return ['success' => false, 'error' => 'Body too small (' . strlen($body) . ' bytes)', 'is_hero' => false];
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'unit_img_');
            file_put_contents($tmpPath, $body);
            unset($body);

            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $realMime = $finfo->file($tmpPath);
            if (! in_array($realMime, $acceptedMimes, true)) {
                @unlink($tmpPath);

                return ['success' => false, 'error' => "File MIME: {$realMime}", 'is_hero' => false];
            }

            $extension = match ($realMime) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/png'               => 'png',
                'image/webp'              => 'webp',
                'image/gif'               => 'gif',
                default                   => 'jpg',
            };

            $fileName = 'listing-' . $listing->id . '-' . uniqid() . '.' . $extension;

            $media = $listing
                ->addMedia($tmpPath)
                ->usingFileName($fileName)
                ->toMediaCollection('gallery');

            if ($isHero) {
                $media->setCustomProperty('is_hero', true);
                $media->save();
            }

            return ['success' => true, 'media_id' => $media->id, 'is_hero' => $isHero];

        } catch (Throwable $e) {
            Log::error('ListingImageService: Download exception', [
                'listing_id' => $listing->id,
                'url'        => $url,
                'error'      => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'is_hero' => false];
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Build browser-like request headers from config.
     *
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent'       => config('scraper.browser_headers.user_agent'),
            'Accept'           => config('scraper.browser_headers.accept_image'),
            'Accept-Language'  => config('scraper.browser_headers.accept_language'),
            'Referer'          => config('scraper.otodom.base_url', 'https://www.otodom.pl') . '/',
            'Sec-Fetch-Dest'   => 'image',
            'Sec-Fetch-Mode'   => 'no-cors',
            'Sec-Fetch-Site'   => 'cross-site',
        ];
    }

    private function hasHero(Listing $listing): bool
    {
        return $listing->getMedia('gallery')
            ->contains(fn (Media $m): bool => $m->getCustomProperty('is_hero') === true);
    }
}
