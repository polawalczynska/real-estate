<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Listing;

interface ImageAttacherInterface
{
    /**
     * Attach the best images to a listing.
     *
     * @param  Listing     $listing         The Eloquent listing.
     * @param  array|null  $selectedImages  AI selection payload (hero_url, gallery_urls).
     * @param  array       $fallbackUrls    Raw image URLs extracted from HTML.
     * @return array{hero_attached: bool, gallery_count: int, errors: list<string>}
     */
    public function attachImages(
        Listing $listing,
        ?array $selectedImages = null,
        array $fallbackUrls = [],
    ): array;
}
