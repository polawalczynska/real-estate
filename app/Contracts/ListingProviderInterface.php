<?php

declare(strict_types=1);

namespace App\Contracts;

interface ListingProviderInterface
{
    /**
     * Fetch raw listing data from the provider
     *
     * @param int $limit Maximum number of listings to fetch
     * @return array<int, array<string, mixed>> Array of raw listing data
     */
    public function fetch(int $limit = 10): array;
}
