<?php

declare(strict_types=1);

namespace App\Enums;

enum PropertyType: string
{
    case APARTMENT = 'apartment';
    case HOUSE = 'house';
    case LOFT = 'loft';
    case TOWNHOUSE = 'townhouse';
    case STUDIO = 'studio';
    case PENTHOUSE = 'penthouse';
    case VILLA = 'villa';
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::APARTMENT => 'Apartment',
            self::HOUSE => 'House',
            self::LOFT => 'Loft',
            self::TOWNHOUSE => 'Townhouse',
            self::STUDIO => 'Studio',
            self::PENTHOUSE => 'Penthouse',
            self::VILLA => 'Villa',
            self::UNKNOWN => 'Unknown',
        };
    }

    /**
     * Resolve a string value to a PropertyType, falling back to UNKNOWN.
     */
    public static function fromSafe(string $value): self
    {
        return self::tryFrom($value) ?? self::UNKNOWN;
    }
}
