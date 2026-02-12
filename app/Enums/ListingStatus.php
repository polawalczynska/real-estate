<?php

declare(strict_types=1);

namespace App\Enums;

enum ListingStatus: string
{
    case AVAILABLE = 'available';
    case SOLD = 'sold';
    case RENTED = 'rented';
    case PENDING = 'pending';
    case WITHDRAWN = 'withdrawn';
    case UNVERIFIED = 'unverified';

    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Available',
            self::SOLD => 'Sold',
            self::RENTED => 'Rented',
            self::PENDING => 'Pending',
            self::WITHDRAWN => 'Withdrawn',
            self::UNVERIFIED => 'Unverified',
        };
    }
}
