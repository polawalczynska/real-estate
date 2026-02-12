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

class Listing extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Lightweight column set for listing cards (avoids raw_data). */
    public const CARD_COLUMNS = [
        'id', 'title', 'price', 'currency', 'area_m2', 'rooms',
        'city', 'street', 'type', 'status', 'keywords', 'created_at', 'updated_at',
    ];

    /** Rolling window for fingerprint-based deduplication. */
    private const DUPLICATE_WINDOW_DAYS = 30;

    private const MEDIA_QUALITY  = 80;
    private const MEDIA_SHARPEN  = 10;
    private const MEDIA_FORMAT   = 'webp';

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
        'raw_data',
        'images',
        'keywords',
        'last_seen_at',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'area_m2'      => 'decimal:2',
        'rooms'        => 'integer',
        'type'         => PropertyType::class,
        'status'       => ListingStatus::class,
        'raw_data'     => 'array',
        'images'       => 'array',
        'keywords'     => 'array',
        'last_seen_at' => 'datetime',
    ];

    // ─── Media Collections & Conversions ────────────────────────

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
            ->nonQueued()
            ->sharpen(self::MEDIA_SHARPEN)
            ->performOnCollections('gallery');

        $this->addMediaConversion('card')
            ->width(800)->height(1000)
            ->format(self::MEDIA_FORMAT)
            ->quality(self::MEDIA_QUALITY)
            ->nonQueued()
            ->sharpen(self::MEDIA_SHARPEN)
            ->performOnCollections('gallery');

        $this->addMediaConversion('thumb')
            ->width(200)->height(200)
            ->format(self::MEDIA_FORMAT)
            ->quality(self::MEDIA_QUALITY)
            ->nonQueued()
            ->sharpen(self::MEDIA_SHARPEN)
            ->performOnCollections('gallery');
    }

    // ─── Hero Image Accessors ───────────────────────────────────

    public function hasHeroImage(): bool
    {
        return $this->getHeroMedia() !== null;
    }

    public function getHeroImageUrl(string $conversion = 'card'): string
    {
        $hero = $this->getHeroMedia();

        if ($hero === null) {
            return '';
        }

        $url = $hero->getUrl($conversion);

        return $url !== '' ? $url : $hero->getUrl();
    }

    public function getHeroMedia(): ?Media
    {
        $gallery = $this->getMedia('gallery');

        if ($gallery->isEmpty()) {
            return null;
        }

        return $gallery->first(fn (Media $m): bool => $m->getCustomProperty('is_hero') === true)
            ?? $gallery->first();
    }

    public function hasGalleryImages(): bool
    {
        return $this->getMedia('gallery')->isNotEmpty();
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

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::AVAILABLE);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [ListingStatus::AVAILABLE, ListingStatus::PENDING]);
    }

    public function scopeOfType(Builder $query, PropertyType $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeInCity(Builder $query, string $city): Builder
    {
        return $query->where('city', $city);
    }
}
