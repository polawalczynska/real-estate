<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ListingDTO;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Models\Listing;
use App\Services\Ai\HtmlExtractorService;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Central domain service for listing persistence and lifecycle.
 *
 * Owns: skeleton creation, pre-AI dedup, post-AI normalisation
 * application, duplicate merging, and hero-image designation.
 */
final class ListingService
{
    private const PRICE_CHANGE_TOLERANCE = 0.005;

    public function __construct(
        private readonly Listing $listing,
        private readonly HtmlExtractorService $htmlExtractor,
    ) {}

    /**
     * Create a minimal listing record that appears on site immediately.
     *
     * Before creating the skeleton, calculates a semantic fingerprint
     * from raw HTML metadata and checks for recent duplicates.
     * If a duplicate is found the AI/media pipeline is skipped entirely.
     *
     * @return array{listing: Listing, is_new: bool, is_fingerprint_duplicate: bool}
     */
    public function createSkeleton(array $scrapedData): array
    {
        $externalId = $scrapedData['external_id'];
        $rawHtml    = $scrapedData['raw_html'] ?? '';

        $fingerprint = $this->calculatePreAiFingerprint($rawHtml);

        if ($fingerprint !== null) {
            $duplicate = $this->listing
                ->newQuery()
                ->recentDuplicate($fingerprint)
                ->first();

            if ($duplicate !== null) {
                $this->refreshDuplicate($duplicate, $scrapedData);

                Log::info('Semantic duplicate detected — skipping AI processing.', [
                    'fingerprint' => $fingerprint,
                    'existing_id' => $duplicate->id,
                    'external_id' => $externalId,
                ]);

                return [
                    'listing'                  => $duplicate,
                    'is_new'                   => false,
                    'is_fingerprint_duplicate' => true,
                ];
            }
        }

        $existing = $this->listing
            ->newQuery()
            ->where('external_id', $externalId)
            ->first();

        if ($existing !== null) {
            $existing->update(['last_seen_at' => now()]);

            return [
                'listing'                  => $existing,
                'is_new'                   => false,
                'is_fingerprint_duplicate' => false,
            ];
        }

        $listing = $this->listing->create([
            'external_id'  => $externalId,
            'fingerprint'  => $fingerprint,
            'title'        => $scrapedData['title'] ?: 'Property Listing',
            'description'  => '',
            'price'        => 0,
            'currency'     => 'PLN',
            'area_m2'      => 0,
            'rooms'        => 0,
            'city'         => '',
            'street'       => null,
            'type'         => PropertyType::APARTMENT->value,
            'status'       => ListingStatus::PENDING->value,
            'raw_data'     => [
                'html'             => $rawHtml,
                'url'              => $scrapedData['url'] ?? '',
                'extracted_images' => $scrapedData['extracted_images'] ?? [],
                'scraped_at'       => $scrapedData['scraped_at'] ?? now()->toIso8601String(),
            ],
            'last_seen_at' => now(),
        ]);

        return [
            'listing'                  => $listing,
            'is_new'                   => true,
            'is_fingerprint_duplicate' => false,
        ];
    }

    /**
     * Apply AI-normalised data to a skeleton listing.
     *
     * Performs post-AI duplicate detection, determines the correct
     * status, persists normalised fields, and updates hero designation.
     *
     * @return array{merged: bool}  merged=true when skeleton was a duplicate and was deleted.
     */
    public function applyNormalization(Listing $skeleton, ListingDTO $dto): array
    {
        if ($this->mergePostAiDuplicate($skeleton, $dto)) {
            return ['merged' => true];
        }

        $status = $this->determinePostAiStatus($dto);

        $rawData        = is_array($skeleton->raw_data) ? $skeleton->raw_data : [];
        $selectedImages = $dto->rawData['selected_images'] ?? null;

        if ($selectedImages !== null) {
            $rawData['selected_images'] = $selectedImages;
        }
        $rawData['extracted_images'] = $dto->images ?? $rawData['extracted_images'] ?? [];

        $skeleton->update([
            'title'       => $dto->title,
            'description' => $dto->description,
            'price'       => $dto->price,
            'currency'    => $dto->currency,
            'area_m2'     => $dto->areaM2,
            'rooms'       => $dto->rooms,
            'city'        => $dto->city,
            'street'      => $dto->street,
            'type'        => $dto->type->value,
            'status'      => $status->value,
            'fingerprint' => $dto->fingerprint(),
            'keywords'    => $dto->keywords,
            'images'      => $dto->images,
            'raw_data'    => $rawData,
        ]);

        $this->updateHeroDesignation($skeleton, $selectedImages);

        Log::info("AI normalization applied — listing [{$skeleton->id}] is now {$status->value}.", [
            'listing_id' => $skeleton->id,
            'status'     => $status->value,
        ]);

        return ['merged' => false];
    }

    /**
     * Mark a listing as unverified when all AI retries are exhausted.
     */
    public function markUnverified(int $listingId): void
    {
        $listing = Listing::find($listingId);

        if ($listing !== null && $listing->status === ListingStatus::PENDING) {
            $listing->update(['status' => ListingStatus::UNVERIFIED->value]);
        }
    }

    /**
     * Post-AI upsert with fingerprint + external_id dedup.
     *
     * Safety net for edge cases where the AI produced more accurate
     * city/street values, changing the fingerprint after skeleton creation.
     *
     * @return array{listing: Listing, is_duplicate: bool}
     */
    public function upsert(ListingDTO $dto): array
    {
        $attributes  = $dto->toArray();
        $fingerprint = $dto->fingerprint();

        $duplicate = $this->listing
            ->newQuery()
            ->recentDuplicate($fingerprint)
            ->when(
                $dto->externalId !== null,
                fn ($q) => $q->where('external_id', '!=', $dto->externalId),
            )
            ->first();

        if ($duplicate !== null) {
            $duplicate->update(array_merge($attributes, ['last_seen_at' => now()]));

            Log::info('Post-AI semantic duplicate merged.', [
                'listing_id'  => $duplicate->id,
                'fingerprint' => $fingerprint,
            ]);

            return ['listing' => $duplicate->fresh(), 'is_duplicate' => true];
        }

        if ($dto->externalId !== null) {
            $existing = $this->listing
                ->newQuery()
                ->where('external_id', $dto->externalId)
                ->first();

            if ($existing !== null) {
                $existing->update(array_merge($attributes, ['last_seen_at' => now()]));

                return ['listing' => $existing->fresh(), 'is_duplicate' => true];
            }
        }

        $listing = $this->listing->create(
            array_merge($attributes, ['last_seen_at' => now()]),
        );

        return ['listing' => $listing, 'is_duplicate' => false];
    }

    private function calculatePreAiFingerprint(string $rawHtml): ?string
    {
        if ($rawHtml === '') {
            return null;
        }

        $meta = $this->htmlExtractor->extractStructuredMetadata($rawHtml);

        if ($meta['price'] <= 0 && $meta['city'] === '') {
            return null;
        }

        return FingerprintService::fromRawMetadata($meta);
    }

    private function refreshDuplicate(Listing $existing, array $scrapedData): void
    {
        $updates = ['last_seen_at' => now()];

        $rawHtml = $scrapedData['raw_html'] ?? '';
        if ($rawHtml !== '') {
            $meta = $this->htmlExtractor->extractStructuredMetadata($rawHtml);
            if ($meta['price'] > 0) {
                $oldPrice = (float) $existing->price;
                $newPrice = $meta['price'];

                if ($oldPrice > 0 && abs($newPrice - $oldPrice) / $oldPrice > self::PRICE_CHANGE_TOLERANCE) {
                    $updates['price'] = $newPrice;

                    Log::info('Duplicate price updated.', [
                        'listing_id' => $existing->id,
                        'old_price'  => $oldPrice,
                        'new_price'  => $newPrice,
                    ]);
                }
            }
        }

        $existing->update($updates);
    }

    /**
     * Check for a cross-platform duplicate using the AI-refined fingerprint.
     *
     * If found: updates the existing record and deletes the skeleton.
     */
    private function mergePostAiDuplicate(Listing $skeleton, ListingDTO $dto): bool
    {
        $fingerprint = $dto->fingerprint();

        $existing = Listing::query()
            ->recentDuplicate($fingerprint)
            ->where('id', '!=', $skeleton->id)
            ->first();

        if ($existing === null) {
            return false;
        }

        Log::warning('Post-AI semantic duplicate detected — merging and discarding skeleton.', [
            'fingerprint' => $fingerprint,
            'skeleton_id' => $skeleton->id,
            'existing_id' => $existing->id,
        ]);

        $updates = ['last_seen_at' => now()];
        if ($dto->price > 0 && abs($dto->price - (float) $existing->price) > 0) {
            $updates['price'] = $dto->price;
        }
        $existing->update($updates);

        $skeleton->delete();

        return true;
    }

    private function determinePostAiStatus(ListingDTO $dto): ListingStatus
    {
        if ($dto->price === 0.0 || $dto->areaM2 === 0.0 || $dto->city === 'Unknown') {
            return ListingStatus::UNVERIFIED;
        }

        return ListingStatus::AVAILABLE;
    }

    /**
     * Re-designate the hero image based on AI curation.
     *
     * If the media job already attached images (it runs in parallel),
     * match the AI-selected hero URL against `source_url` custom properties.
     */
    private function updateHeroDesignation(Listing $listing, ?array $selectedImages): void
    {
        if ($selectedImages === null || empty($selectedImages['hero_url'])) {
            return;
        }

        $gallery = $listing->fresh()?->getMedia('gallery');
        if ($gallery === null || $gallery->isEmpty()) {
            return;
        }

        $heroUrl = $selectedImages['hero_url'];

        $gallery->each(function (Media $media): void {
            if ($media->getCustomProperty('is_hero') === true) {
                $media->setCustomProperty('is_hero', false);
                $media->save();
            }
        });

        $matched = $gallery->first(
            fn (Media $m): bool => $m->getCustomProperty('source_url') === $heroUrl,
        );

        if ($matched !== null) {
            $matched->setCustomProperty('is_hero', true);
            $matched->save();

            return;
        }

        $first = $gallery->first();
        if ($first !== null) {
            $first->setCustomProperty('is_hero', true);
            $first->save();
        }
    }
}
