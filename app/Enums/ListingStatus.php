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
    case INCOMPLETE = 'incomplete';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Available',
            self::SOLD => 'Sold',
            self::RENTED => 'Rented',
            self::PENDING => 'Pending',
            self::WITHDRAWN => 'Withdrawn',
            self::UNVERIFIED => 'Unverified',
            self::INCOMPLETE => 'Incomplete',
            self::FAILED => 'Failed',
        };
    }

    /**
     * Whether this status should be visible to end-users browsing listings.
     */
    public function isVisible(): bool
    {
        return in_array($this, [self::AVAILABLE, self::PENDING], true);
    }
}
