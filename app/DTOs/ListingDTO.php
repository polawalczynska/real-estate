<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Services\FingerprintService;
use Illuminate\Support\Str;

readonly class ListingDTO
{
    public function __construct(
        public ?string $externalId,
        public string $title,
        public string $description,
        public float $price,
        public string $currency,
        public float $areaM2,
        public int $rooms,
        public string $city,
        public ?string $street,
        public PropertyType $type,
        public ListingStatus $status,
        public array $rawData,
        public ?array $images = null,
        public ?array $keywords = null,
    ) {}

    public function fingerprint(): string
    {
        return FingerprintService::calculate(
            city:   $this->city,
            street: $this->street,
            price:  $this->price,
            areaM2: $this->areaM2,
            rooms:  $this->rooms,
        );
    }

    public static function normalizeKeywords(array $raw): array
    {
        $normalized = [];

        foreach ($raw as $keyword) {
            $slug = Str::slug(trim((string) $keyword));
            if ($slug !== '') {
                $normalized[] = $slug;
            }
        }

        return array_values(array_unique($normalized));
    }

    public static function fromArray(array $data): self
    {
        $keywords = $data['keywords'] ?? null;
        if (is_array($keywords) && $keywords !== []) {
            $keywords = self::normalizeKeywords($keywords);
            $keywords = $keywords === [] ? null : $keywords;
        } else {
            $keywords = null;
        }

        return new self(
            externalId: $data['external_id'] ?? null,
            title: $data['title'],
            description: $data['description'] ?? '',
            price: (float) $data['price'],
            currency: $data['currency'] ?? 'PLN',
            areaM2: (float) $data['area_m2'],
            rooms: (int) $data['rooms'],
            city: $data['city'],
            street: $data['street'] ?? null,
            type: PropertyType::from($data['type']),
            status: ListingStatus::from($data['status'] ?? ListingStatus::AVAILABLE->value),
            rawData: $data['raw_data'] ?? $data,
            images: $data['images'] ?? null,
            keywords: $keywords,
        );
    }

    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'fingerprint' => $this->fingerprint(),
            'title'        => $this->title,
            'description'  => $this->description,
            'price'        => $this->price,
            'currency'     => $this->currency,
            'area_m2'      => $this->areaM2,
            'rooms'        => $this->rooms,
            'city'         => $this->city,
            'street'       => $this->street,
            'type'         => $this->type->value,
            'status'       => $this->status->value,
            'raw_data'     => $this->rawData,
            'images'       => $this->images,
            'keywords'     => $this->keywords,
        ];
    }
}
