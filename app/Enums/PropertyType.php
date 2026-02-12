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
        };
    }
}
