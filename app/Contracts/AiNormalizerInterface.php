<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ListingDTO;

interface AiNormalizerInterface
{
    /**
     * Normalize raw listing data using an AI model.
     *
     * @param  array<string, mixed>  $rawData  Raw listing data from a provider.
     */
    public function normalize(array $rawData): ListingDTO;
}
