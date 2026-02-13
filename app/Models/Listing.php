<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Eloquent model for a real-estate listing.
 *
 * A listing passes through a lifecycle:
 *  PENDING → (AI normalisation) → AVAILABLE / INCOMPLETE / FAILED
 *  PENDING → (AI exhausted)     → UNVERIFIED
 *
 * Quality scoring (0–100) and `is_fully_parsed` flag track data completeness.
 * FAILED and INCOMPLETE listings are hidden from user-facing views via scopes.
 *
 * @property int              $id
 * @property string|null      $external_id
 * @property string|null      $fingerprint
 * @property string           $title
 * @property string           $description
 * @property float            $price
 * @property string           $currency
 * @property float            $area_m2
 * @property int              $rooms
 * @property string           $city
 * @property string|null      $street
 * @property PropertyType     $type
 * @property ListingStatus    $status
 * @property int              $quality_score
 * @property bool             $is_fully_parsed
 * @property array|null       $raw_data
 * @property array|null       $images
 * @property array|null       $keywords
 * @property \Carbon\Carbon|null $last_seen_at
 * @property \Carbon\Carbon   $created_at
 * @property \Carbon\Carbon   $updated_at
 */
class Listing extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Lightweight column set for listing cards (avoids raw_data). */
    public const CARD_COLUMNS = [
        'id', 'title', 'price', 'currency', 'area_m2', 'rooms',
        'city', 'street', 'type', 'status', 'quality_score', 'is_fully_parsed',
        'images', 'keywords', 'created_at', 'updated_at',
    ];

    /** Rolling window for fingerprint-based deduplication. */
    private const DUPLICATE_WINDOW_DAYS = 30;

    private const MEDIA_QUALITY = 80;
    private const MEDIA_SHARPEN = 10;
    private const MEDIA_FORMAT  = 'webp';

    protected $fillable = [
        'external_id',
        'fingerprint',
        'title',
        'description',
        'price',
        'currency',
        'area_m2',
        'rooms',
        'city',
        'street',
        'type',
        'status',
        'quality_score',
        'is_fully_parsed',
        'raw_data',
        'images',
        'keywords',
        'last_seen_at',
    ];

    protected $casts = [
        'price'           => 'decimal:2',
        'area_m2'         => 'decimal:2',
        'rooms'           => 'integer',
        'quality_score'   => 'integer',
        'is_fully_parsed' => 'boolean',
        'type'            => PropertyType::class,
        'status'          => ListingStatus::class,
        'raw_data'        => 'array',
        'images'          => 'array',
        'keywords'        => 'array',
        'last_seen_at'    => 'datetime',
    ];

    // ─── Media Collections & Conversions ────────────────────────────

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery')
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
            ]);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('hero')
            ->width(1920)->height(1080)
            ->format(self::MEDIA_FORMAT)
            ->quality(self::MEDIA_QUALITY)
            ->queued()
            ->sharpen(self::MEDIA_SHARPEN)
            ->performOnCollections('gallery');

        $this->addMediaConversion('card')
            ->width(800)->height(1000)
            ->format(self::MEDIA_FORMAT)
            ->quality(self::MEDIA_QUALITY)
            ->queued()
            ->sharpen(self::MEDIA_SHARPEN)
            ->performOnCollections('gallery');

        $this->addMediaConversion('thumb')
            ->width(200)->height(200)
            ->format(self::MEDIA_FORMAT)
            ->quality(self::MEDIA_QUALITY)
            ->queued()
            ->sharpen(self::MEDIA_SHARPEN)
            ->performOnCollections('gallery');
    }

    // ─── Image Accessors ────────────────────────────────────────────

    /**
     * Whether the listing has a hero image available (AI-selected, extracted, or from images array).
     */
    public function hasHeroImage(): bool
    {
        return $this->getHeroImageUrl() !== '';
    }

    /**
     * Resolve the best hero image URL.
     *
     * Priority: images[] (always loaded) → AI-selected hero → extracted images.
     * The `images` column is included in CARD_COLUMNS so it's available on
     * listing index pages. The `raw_data` column is only loaded on detail pages.
     */
    public function getHeroImageUrl(string $conversion = 'card'): string
    {
        // Priority 1: Images array (lightweight, always loaded on card pages)
        $images = is_array($this->images) ? $this->images : [];
        if ($images !== [] && is_string($images[0])) {
            return $images[0];
        }

        // Priority 2+3 rely on raw_data (only available on detail pages)
        $rawData = is_array($this->raw_data) ? $this->raw_data : [];

        // Priority 2: AI-selected hero
        $heroUrl = data_get($rawData, 'selected_images.hero_url', '');
        if ($heroUrl !== '') {
            return $heroUrl;
        }

        // Priority 3: First extracted image
        $extractedImages = data_get($rawData, 'extracted_images', []);
        if (is_array($extractedImages) && $extractedImages !== []) {
            $firstImage = is_array($extractedImages[0])
                ? ($extractedImages[0]['url'] ?? '')
                : (string) $extractedImages[0];
            if ($firstImage !== '') {
                return $firstImage;
            }
        }

        return '';
    }

    /**
     * Get the Spatie Media hero item (with `is_hero` custom property) or the first gallery item.
     */
    public function getHeroMedia(): ?Media
    {
        $gallery = $this->getMedia('gallery');

        if ($gallery->isEmpty()) {
            return null;
        }

        return $gallery->first(fn (Media $m): bool => $m->getCustomProperty('is_hero') === true)
            ?? $gallery->first();
    }

    /**
     * Whether the listing has any gallery images from any source.
     */
    public function hasGalleryImages(): bool
    {
        $rawData = is_array($this->raw_data) ? $this->raw_data : [];
        $extractedImages = data_get($rawData, 'extracted_images', []);

        return (is_array($extractedImages) && $extractedImages !== [])
            || (is_array($this->images) && $this->images !== []);
    }

    /**
     * Get all gallery image URLs from AI curation and extracted sources.
     *
     * @return list<string>
     */
    public function getGalleryImageUrls(): array
    {
        $rawData = is_array($this->raw_data) ? $this->raw_data : [];
        $urls    = [];

        // AI-selected gallery images
        $galleryUrls = data_get($rawData, 'selected_images.gallery_urls', []);
        if (is_array($galleryUrls)) {
            $urls = array_merge($urls, $galleryUrls);
        }

        // Extracted images (excluding hero)
        $extractedImages = data_get($rawData, 'extracted_images', []);
        if (is_array($extractedImages)) {
            $heroUrl = $this->getHeroImageUrl();
            foreach ($extractedImages as $img) {
                $url = is_array($img) ? ($img['url'] ?? '') : (string) $img;
                if ($url !== '' && $url !== $heroUrl && ! in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        // Fall back to images array
        if ($urls === []) {
            $images = is_array($this->images) ? $this->images : [];
            foreach ($images as $img) {
                if (is_string($img) && $img !== '') {
                    $urls[] = $img;
                }
            }
        }

        return array_values(array_filter($urls));
    }

    // ─── Status Helpers ─────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === ListingStatus::PENDING;
    }

    // ─── Query Scopes ───────────────────────────────────────────────

    /**
     * Scope: find a recent duplicate by fingerprint within the dedup window.
     */
    public function scopeRecentDuplicate(Builder $query, string $fingerprint): Builder
    {
        return $query
            ->where('fingerprint', $fingerprint)
            ->where('updated_at', '>=', now()->subDays(self::DUPLICATE_WINDOW_DAYS));
    }

    /**
     * Scope: apply user-facing search filters.
     *
     * When no explicit status filter is provided, FAILED and INCOMPLETE
     * listings are automatically excluded to protect data quality.
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        if (isset($filters['price_min'])) {
            $query->where('price', '>=', (float) $filters['price_min']);
        }

        if (isset($filters['price_max'])) {
            $query->where('price', '<=', (float) $filters['price_max']);
        }

        if (isset($filters['area_min'])) {
            $query->where('area_m2', '>=', (float) $filters['area_min']);
        }

        if (isset($filters['area_max'])) {
            $query->where('area_m2', '<=', (float) $filters['area_max']);
        }

        if (isset($filters['rooms_min'])) {
            $query->where('rooms', '>=', (int) $filters['rooms_min']);
        }

        if (isset($filters['rooms_max'])) {
            $query->where('rooms', '<=', (int) $filters['rooms_max']);
        }

        if (isset($filters['city']) && $filters['city'] !== '') {
            $query->where('city', 'like', '%' . $filters['city'] . '%');
        }

        if (isset($filters['type']) && $filters['type'] !== '') {
            $query->where('type', PropertyType::from($filters['type']));
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', ListingStatus::from($filters['status']));
        } else {
            // Default: show only user-visible statuses
            $query->whereIn('status', [ListingStatus::AVAILABLE, ListingStatus::PENDING]);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $searchTerm = $filters['search'];
            $query->where(function (Builder $q) use ($searchTerm): void {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                    ->orWhere('description', 'like', '%' . $searchTerm . '%')
                    ->orWhere('city', 'like', '%' . $searchTerm . '%')
                    ->orWhere('street', 'like', '%' . $searchTerm . '%');
            });
        }

        if (! empty($filters['keywords']) && is_array($filters['keywords'])) {
            foreach ($filters['keywords'] as $keyword) {
                $keyword = trim((string) $keyword);
                if ($keyword === '') {
                    continue;
                }

                $query->where(function (Builder $inner) use ($keyword): void {
                    $inner->whereJsonContains('keywords', $keyword)
                        ->orWhere('title', 'like', '%' . $keyword . '%')
                        ->orWhere('description', 'like', '%' . $keyword . '%');
                });
            }
        }

        return $query;
    }

    /**
     * Scope: only AVAILABLE listings (fully processed).
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::AVAILABLE);
    }

    /**
     * Scope: user-visible listings (AVAILABLE + PENDING, excluding FAILED/INCOMPLETE).
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [ListingStatus::AVAILABLE, ListingStatus::PENDING]);
    }

    /**
     * Scope: listings of a specific property type.
     */
    public function scopeOfType(Builder $query, PropertyType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: listings in a specific city.
     */
    public function scopeInCity(Builder $query, string $city): Builder
    {
        return $query->where('city', $city);
    }
}
