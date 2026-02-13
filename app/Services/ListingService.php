<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ListingDTO;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Models\Listing;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Central domain service for listing persistence and lifecycle.
 *
 * Owns: skeleton creation, pre-AI dedup, post-AI normalisation
 * application, quality scoring, duplicate merging, and hero-image designation.
 *
 * This service is the single authority on "how a listing gets into the DB".
 * Controllers, commands, and jobs delegate all persistence logic here.
 */
final class ListingService
{
    /** Price must change by more than 0.5 % to trigger an update on duplicates. */
    private const PRICE_CHANGE_TOLERANCE = 0.005;

    public function __construct(
        private readonly Listing $listing,
        private readonly QualityScoreService $qualityScore,
    ) {}

    // ─── Skeleton Creation (Phase 1) ───────────────────────────────

    /**
     * Create a listing record populated with JSON-LD data when available.
     *
     * Phase 1 data (JSON-LD) provides accurate price, city, area, and rooms
     * for a meaningful fingerprint BEFORE any AI call. This enables reliable
     * pre-AI deduplication and richer skeleton records.
     *
     * @param  array<string, mixed>  $scrapedData  Raw data from the provider.
     * @return array{listing: Listing, is_new: bool, is_fingerprint_duplicate: bool}
     */
    public function createSkeleton(array $scrapedData): array
    {
        $externalId = $scrapedData['external_id'];
        $rawHtml    = $scrapedData['raw_html'] ?? '';
        $jsonLd     = $scrapedData['json_ld'] ?? null;

        $fingerprint = $jsonLd !== null
            ? $this->calculateJsonLdFingerprint($jsonLd)
            : null;

        // ── Pre-AI deduplication via fingerprint ─────────────────
        if ($fingerprint !== null) {
            $duplicate = $this->listing
                ->newQuery()
                ->recentDuplicate($fingerprint)
                ->first();

            if ($duplicate !== null) {
                $this->refreshDuplicate($duplicate, $scrapedData);

                Log::debug('Semantic duplicate detected — skipping AI processing.', [
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

        // ── External-ID deduplication ────────────────────────────
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

        // ── Create skeleton ──────────────────────────────────────
        $rawDataPayload = [
            'html'             => $rawHtml,
            'url'              => $scrapedData['url'] ?? '',
            'extracted_images' => $scrapedData['extracted_images'] ?? [],
            'scraped_at'       => $scrapedData['scraped_at'] ?? now()->toIso8601String(),
        ];

        if ($jsonLd !== null) {
            $rawDataPayload['json_ld'] = $jsonLd;
        }

        $listing = $this->listing->create([
            'external_id'  => $externalId,
            'fingerprint'  => $fingerprint,
            'title'        => data_get($jsonLd, 'title', $scrapedData['title'] ?? '') ?: 'Property Listing',
            'description'  => data_get($jsonLd, 'description', ''),
            'price'        => data_get($jsonLd, 'price', 0),
            'currency'     => data_get($jsonLd, 'currency', 'PLN'),
            'area_m2'      => data_get($jsonLd, 'area_m2', 0),
            'rooms'        => data_get($jsonLd, 'rooms', 0),
            'city'         => data_get($jsonLd, 'city', ''),
            'street'       => data_get($jsonLd, 'street'),
            'type'         => data_get($jsonLd, 'type') ?? PropertyType::APARTMENT->value,
            'status'       => ListingStatus::PENDING->value,
            'raw_data'     => $rawDataPayload,
            'last_seen_at' => now(),
        ]);

        return [
            'listing'                  => $listing,
            'is_new'                   => true,
            'is_fingerprint_duplicate' => false,
        ];
    }

    // ─── AI Normalisation Application (Phase 2) ────────────────────

    /**
     * Apply AI-normalised data to a skeleton listing.
     *
     * Pipeline:
     *  1. Post-AI duplicate detection (merge if found).
     *  2. DTO Validation — check critical fields (price, area, city).
     *  3. Quality Scoring — score data completeness (0–100).
     *  4. Status Resolution — AVAILABLE / INCOMPLETE / FAILED.
     *  5. Persist normalised fields, score, and status.
     *  6. Update hero-image designation.
     *
     * @return array{merged: bool, quality_score: int, status: string}
     */
    public function applyNormalization(Listing $skeleton, ListingDTO $dto): array
    {
        if ($this->mergePostAiDuplicate($skeleton, $dto)) {
            return ['merged' => true, 'quality_score' => 0, 'status' => 'merged'];
        }

        // ── Validate → Score → Resolve Status ───────────────────
        $resolvedStatus = $this->qualityScore->applyToListing($skeleton, $dto);
        $finalStatus    = $resolvedStatus;

        // ── Persist normalised fields ────────────────────────────
        $rawData        = is_array($skeleton->raw_data) ? $skeleton->raw_data : [];
        $selectedImages = data_get($dto->rawData, 'selected_images');

        if ($selectedImages !== null) {
            $rawData['selected_images'] = $selectedImages;
        }
        $rawData['extracted_images'] = $dto->images ?? $rawData['extracted_images'] ?? [];

        $skeleton->update([
            'title'           => $dto->title,
            'description'     => $dto->description,
            'price'           => $dto->price,
            'currency'        => $dto->currency,
            'area_m2'         => $dto->areaM2,
            'rooms'           => $dto->rooms,
            'city'            => $dto->city,
            'street'          => $dto->street,
            'type'            => $dto->type->value,
            'status'          => $finalStatus->value,
            'quality_score'   => $dto->qualityScore(),
            'is_fully_parsed' => $dto->isFullyParsed(),
            'fingerprint'     => $dto->fingerprint(),
            'keywords'        => $dto->keywords,
            'images'          => $dto->images,
            'raw_data'        => $rawData,
        ]);

        // ── Hero designation ─────────────────────────────────────
        $this->updateHeroDesignation($skeleton, $selectedImages);

        Log::debug("AI normalization applied — listing [{$skeleton->id}]", [
            'listing_id'      => $skeleton->id,
            'status'          => $finalStatus->value,
            'quality_score'   => $dto->qualityScore(),
            'is_fully_parsed' => $dto->isFullyParsed(),
            'is_valid'        => $dto->isValid(),
        ]);

        return [
            'merged'        => false,
            'quality_score' => $dto->qualityScore(),
            'status'        => $finalStatus->value,
        ];
    }

    // ─── Status Management ─────────────────────────────────────────

    /**
     * Mark a listing as UNVERIFIED when all AI retries are exhausted.
     */
    public function markUnverified(int $listingId): void
    {
        $listing = Listing::find($listingId);

        if ($listing !== null && $listing->status === ListingStatus::PENDING) {
            $listing->update(['status' => ListingStatus::UNVERIFIED->value]);
        }
    }

    // ─── Post-AI Upsert ────────────────────────────────────────────

    /**
     * Upsert a listing with fingerprint + external_id dedup.
     *
     * Safety net for edge cases where the AI produced more accurate
     * city/street values, changing the fingerprint after skeleton creation.
     * Includes quality scoring and status validation.
     *
     * @return array{listing: Listing, is_duplicate: bool}
     */
    public function upsert(ListingDTO $dto): array
    {
        $attributes  = $dto->toArray();
        $fingerprint = $dto->fingerprint();

        // Override status with validation-aware resolution
        $attributes['status'] = $dto->resolveStatus()->value;

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

            Log::debug('Post-AI semantic duplicate merged.', [
                'listing_id'    => $duplicate->id,
                'fingerprint'   => $fingerprint,
                'quality_score' => $dto->qualityScore(),
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

    // ─── Fingerprint Calculation ───────────────────────────────────

    /**
     * Calculate a semantic fingerprint from JSON-LD data.
     *
     * Returns null if the data lacks enough information (no price AND no city),
     * preventing false-positive matches on empty data.
     */
    private function calculateJsonLdFingerprint(array $jsonLd): ?string
    {
        $price = (float) ($jsonLd['price'] ?? 0);
        $city  = (string) ($jsonLd['city'] ?? '');

        if ($price <= 0 && $city === '') {
            return null;
        }

        return FingerprintService::calculate(
            city:   $city,
            street: $jsonLd['street'] ?? null,
            price:  $price,
            areaM2: (float) ($jsonLd['area_m2'] ?? 0),
            rooms:  (int) ($jsonLd['rooms'] ?? 0),
        );
    }

    // ─── Duplicate Handling ────────────────────────────────────────

    /**
     * Refresh a known duplicate: update last_seen_at and detect price changes.
     */
    private function refreshDuplicate(Listing $existing, array $scrapedData): void
    {
        $updates = ['last_seen_at' => now()];
        $jsonLd  = $scrapedData['json_ld'] ?? null;

        if ($jsonLd !== null && ($jsonLd['price'] ?? 0) > 0) {
            $oldPrice = (float) $existing->price;
            $newPrice = (float) $jsonLd['price'];

            if ($oldPrice > 0 && abs($newPrice - $oldPrice) / $oldPrice > self::PRICE_CHANGE_TOLERANCE) {
                $updates['price'] = $newPrice;

                Log::debug('Duplicate price updated.', [
                    'listing_id' => $existing->id,
                    'old_price'  => $oldPrice,
                    'new_price'  => $newPrice,
                ]);
            }
        }

        $existing->update($updates);
    }

    /**
     * Detect a cross-platform duplicate using the AI-refined fingerprint.
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

    // ─── Hero Image ────────────────────────────────────────────────

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
