<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ImageAttacherInterface;
use App\Models\Listing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Image download job — runs on the `media` queue.
 *
 * Downloads listing images in parallel with AI normalization.
 * Reads image URLs from the listing's `raw_data.extracted_images`
 * or from AI curation if already available.
 *
 * Stores `source_url` as a custom property on each media item
 * so the AI job can later match and re-designate the hero image.
 */
final class DownloadListingImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    // Backoff delays: 1 min, 3 min, 5 min.
    public function backoff(): array
    {
        return [60, 180, 300];
    }

    public function __construct(
        private readonly int $listingId,
        private readonly array $extractedImages = [],
    ) {
        $this->onQueue('media');
    }

    public function handle(ImageAttacherInterface $imageService): void
    {
        $listing = Listing::find($this->listingId);

        if ($listing === null) {
            Log::warning('Image job: Listing not found', ['listing_id' => $this->listingId]);
            return;
        }

        if ($listing->getMedia('gallery')->isNotEmpty()) {
            return;
        }

        try {
            $rawData = is_array($listing->raw_data) ? $listing->raw_data : [];
            $selectedImages = $rawData['selected_images'] ?? null;

            $fallbackUrls = array_map(
                static fn (array $img): string => $img['url'],
                $this->extractedImages,
            );

            if (empty($fallbackUrls)) {
                $fallbackUrls = $rawData['extracted_images'] ?? [];
                if (! is_array($fallbackUrls)) {
                    $fallbackUrls = [];
                }
                $fallbackUrls = array_map(
                    static fn (mixed $img): string => is_array($img) ? ($img['url'] ?? '') : (string) $img,
                    $fallbackUrls,
                );
                $fallbackUrls = array_values(array_filter($fallbackUrls));
            }

            $attachmentSummary = $imageService->attachImages($listing, $selectedImages, $fallbackUrls);

            $this->tagSourceUrls($listing, $fallbackUrls, $selectedImages);

            $listing->touch();

            Log::info("Image download complete — listing [{$this->listingId}] has {$attachmentSummary['gallery_count']} images.", [
                'listing_id'    => $this->listingId,
                'gallery_count' => $attachmentSummary['gallery_count'],
            ]);

        } catch (Throwable $e) {
            Log::error('Image download job failed', [
                'listing_id' => $this->listingId,
                'error'      => $e->getMessage(),
                'attempt'    => $this->attempts(),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Image download permanently failed', [
            'listing_id' => $this->listingId,
            'error'      => $exception?->getMessage(),
            'attempts'   => $this->attempts(),
        ]);
    }

    private function tagSourceUrls(Listing $listing, array $fallbackUrls, ?array $selectedImages): void
    {
        $gallery = $listing->fresh()?->getMedia('gallery');
        if ($gallery === null || $gallery->isEmpty()) {
            return;
        }

        $orderedUrls = [];
        if (! empty($selectedImages['hero_url'])) {
            $orderedUrls[] = $selectedImages['hero_url'];
        }
        foreach ($selectedImages['gallery_urls'] ?? [] as $url) {
            if (! in_array($url, $orderedUrls, true)) {
                $orderedUrls[] = $url;
            }
        }
        foreach ($fallbackUrls as $url) {
            if (! in_array($url, $orderedUrls, true)) {
                $orderedUrls[] = $url;
            }
        }

        foreach ($gallery as $index => $media) {
            if (isset($orderedUrls[$index])) {
                $media->setCustomProperty('source_url', $orderedUrls[$index]);
                $media->save();
            }
        }
    }
}
