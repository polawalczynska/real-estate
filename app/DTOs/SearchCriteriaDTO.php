<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Immutable DTO representing parsed search criteria.
 *
 * Built from AI intent parsing or manual filter forms, then passed
 * to SearchService for Eloquent query construction.
 */
readonly class SearchCriteriaDTO
{
    public function __construct(
        public ?float $priceMin = null,
        public ?float $priceMax = null,
        public ?float $areaMin = null,
        public ?float $areaMax = null,
        public ?int $roomsMin = null,
        public ?int $roomsMax = null,
        public ?string $city = null,
        public ?string $type = null,
        public ?string $search = null,
        public ?array $keywords = null,
        public string $sortBy = 'created_at',
        public string $sortOrder = 'desc',
        public int $perPage = 15,
    ) {}

    public static function fromArray(array $data): self
    {
        $keywords = $data['keywords'] ?? null;
        if (is_array($keywords)) {
            $keywords = array_values(array_filter($keywords, static fn (mixed $v): bool => is_string($v) && $v !== ''));
            $keywords = $keywords === [] ? null : $keywords;
        }

        return new self(
            priceMin: isset($data['price_min']) && $data['price_min'] !== '' ? (float) $data['price_min'] : null,
            priceMax: isset($data['price_max']) && $data['price_max'] !== '' ? (float) $data['price_max'] : null,
            areaMin: isset($data['area_min']) && $data['area_min'] !== '' ? (float) $data['area_min'] : null,
            areaMax: isset($data['area_max']) && $data['area_max'] !== '' ? (float) $data['area_max'] : null,
            roomsMin: isset($data['rooms_min']) && $data['rooms_min'] !== '' ? (int) $data['rooms_min'] : null,
            roomsMax: isset($data['rooms_max']) && $data['rooms_max'] !== '' ? (int) $data['rooms_max'] : null,
            city: ! empty($data['city']) ? (string) $data['city'] : null,
            type: ! empty($data['type']) ? (string) $data['type'] : null,
            search: ! empty($data['search']) ? (string) $data['search'] : null,
            keywords: $keywords,
            sortBy: $data['sort_by'] ?? 'created_at',
            sortOrder: $data['sort_order'] ?? 'desc',
            perPage: isset($data['per_page']) ? (int) $data['per_page'] : 15,
        );
    }

    /**
     * Build filter parameters for Eloquent queries.
     *
     * @return array<string, mixed>
     */
    public function toFilterArray(): array
    {
        return $this->buildParameterMap(keywordsAsArray: true) + [
            'sort_by'    => $this->sortBy,
            'sort_order' => $this->sortOrder,
        ];
    }

    /**
     * Build URL query parameters for routing.
     *
     * @return array<string, mixed>
     */
    public function toQueryParams(): array
    {
        return $this->buildParameterMap(keywordsAsArray: false);
    }

    public function validate(): array
    {
        $errors = [];

        if ($this->priceMin !== null && $this->priceMax !== null && $this->priceMin > $this->priceMax) {
            $errors['price'] = 'Minimum price cannot be higher than maximum price.';
        }
        if ($this->areaMin !== null && $this->areaMax !== null && $this->areaMin > $this->areaMax) {
            $errors['area'] = 'Minimum area cannot be larger than maximum area.';
        }
        if ($this->roomsMin !== null && $this->roomsMax !== null && $this->roomsMin > $this->roomsMax) {
            $errors['rooms'] = 'Minimum rooms cannot be more than maximum rooms.';
        }
        if ($this->priceMin !== null && $this->priceMin < 0) {
            $errors['price_min'] = 'Price must be positive.';
        }
        if ($this->areaMin !== null && $this->areaMin < 0) {
            $errors['area_min'] = 'Area must be positive.';
        }

        return $errors;
    }

    public function hasActiveFilters(): bool
    {
        return $this->priceMin !== null
            || $this->priceMax !== null
            || $this->areaMin !== null
            || $this->areaMax !== null
            || $this->roomsMin !== null
            || $this->roomsMax !== null
            || $this->city !== null
            || $this->type !== null
            || $this->search !== null
            || $this->keywords !== null;
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Build the shared nullable-parameter map.
     *
     * @return array<string, mixed>
     */
    private function buildParameterMap(bool $keywordsAsArray): array
    {
        $map = [
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'area_min'  => $this->areaMin,
            'area_max'  => $this->areaMax,
            'rooms_min' => $this->roomsMin,
            'rooms_max' => $this->roomsMax,
            'city'      => $this->city,
            'type'      => $this->type,
            'search'    => $this->search,
        ];

        if ($this->keywords !== null) {
            $map['keywords'] = $keywordsAsArray
                ? $this->keywords
                : implode(',', $this->keywords);
        }

        return array_filter($map, static fn (mixed $v): bool => $v !== null);
    }
}
