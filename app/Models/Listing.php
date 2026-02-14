<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Listing extends Model
{
    public const CARD_COLUMNS = [
        'id', 'title', 'price', 'currency', 'area_m2', 'rooms',
        'city', 'street', 'type', 'status', 'quality_score', 'is_fully_parsed',
        'images', 'keywords', 'created_at', 'updated_at',
    ];

    private const DUPLICATE_WINDOW_DAYS = 30;

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

    public function hasHeroImage(): bool
    {
        return $this->getHeroImageUrl() !== '';
    }

    public function getHeroImageUrl(): string
    {
        $rawData = is_array($this->raw_data) ? $this->raw_data : [];

        // Priority 1: AI-selected hero (best quality, AI-curated)
        $heroUrl = data_get($rawData, 'selected_images.hero_url', '');
        if ($heroUrl !== '') {
            return $heroUrl;
        }

        // Priority 2: Images array (lightweight, always loaded on card pages)
        $images = is_array($this->images) ? $this->images : [];
        if ($images !== [] && is_string($images[0])) {
            return $images[0];
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

    public function hasGalleryImages(): bool
    {
        $rawData = is_array($this->raw_data) ? $this->raw_data : [];
        $extractedImages = data_get($rawData, 'extracted_images', []);

        return (is_array($extractedImages) && $extractedImages !== [])
            || (is_array($this->images) && $this->images !== []);
    }


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

    public function isPending(): bool
    {
        return $this->status === ListingStatus::PENDING;
    }


    public function scopeRecentDuplicate(Builder $query, string $fingerprint): Builder
    {
        return $query
            ->where('fingerprint', $fingerprint)
            ->where('updated_at', '>=', now()->subDays(self::DUPLICATE_WINDOW_DAYS));
    }

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
