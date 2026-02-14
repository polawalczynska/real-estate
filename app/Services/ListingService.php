<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ListingDTO;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Models\Listing;
use Illuminate\Support\Facades\Log;

/**
 * Central domain service for listing persistence and lifecycle.
 */
final class ListingService
{
    /** Price must change by more than 0.5 % to trigger an update on duplicates. */
    private const PRICE_CHANGE_TOLERANCE = 0.005;

    public function __construct(
        private readonly Listing $listing,
    ) {}

    /**
     * Create a listing record populated with JSON-LD data when available.
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

    /**
     * Apply AI-normalised data to a skeleton listing.
     */
    public function applyNormalization(Listing $skeleton, ListingDTO $dto): array
    {
        if ($this->mergePostAiDuplicate($skeleton, $dto)) {
            return ['merged' => true, 'quality_score' => 0, 'status' => 'merged'];
        }

        // ── Validate → Score → Resolve Status ───────────────────
        $validationErrors = $dto->validate();
        $qualityScore     = $dto->qualityScore();
        $isFullyParsed    = $dto->isFullyParsed();
        $finalStatus      = $dto->resolveStatus();

        if ($validationErrors !== []) {
            Log::warning('Listing failed critical-field validation', [
                'listing_id' => $skeleton->id,
                'errors'     => $validationErrors,
                'score'      => $qualityScore,
                'status'     => $finalStatus->value,
            ]);
        }

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
            'quality_score'   => $qualityScore,
            'is_fully_parsed' => $isFullyParsed,
            'fingerprint'     => $dto->fingerprint(),
            'keywords'        => $dto->keywords,
            'images'          => $dto->images,
            'raw_data'        => $rawData,
        ]);

        Log::debug("AI normalization applied — listing [{$skeleton->id}]", [
            'listing_id'      => $skeleton->id,
            'status'          => $finalStatus->value,
            'quality_score'   => $qualityScore,
            'is_fully_parsed' => $isFullyParsed,
        ]);

        return [
            'merged'        => false,
            'quality_score' => $qualityScore,
            'status'        => $finalStatus->value,
        ];
    }

    public function markUnverified(int $listingId): void
    {
        $listing = Listing::find($listingId);

        if ($listing !== null && $listing->status === ListingStatus::PENDING) {
            $listing->update(['status' => ListingStatus::UNVERIFIED->value]);
        }
    }

    /**
     * Safety net for edge cases where the AI produced more accurate
     * city/street values, changing the fingerprint after skeleton creation.
     * Includes quality scoring and status validation.
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

}
