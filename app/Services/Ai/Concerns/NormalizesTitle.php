<?php

declare(strict_types=1);

namespace App\Services\Ai\Concerns;

use App\Enums\PropertyType;

/**
 * Structured title building and validation.
 *
 * Format: "[N]-Room [Type] in [City]" or "[N]-Room [Type] on [Street] in [City]".
 * Used both by the AI pipeline (to validate AI output) and the local fallback
 * (to build titles when AI fails or returns non-conforming text).
 */
trait NormalizesTitle
{

    public function buildStructuredTitle(
        string $type,
        int $rooms,
        float $areaM2,
        ?string $street,
        string $city,
    ): string {
        $typeEnum  = PropertyType::fromSafe($type);
        $typeLabel = $typeEnum !== PropertyType::UNKNOWN ? $typeEnum->label() : '';

        $isStudio = $typeEnum === PropertyType::STUDIO;
        $parts    = [];

        if ($isStudio) {
            $parts[] = $typeLabel !== '' ? $typeLabel : 'Studio';
        } else {
            if ($rooms > 0) {
                $parts[] = $rooms . '-Room';
            }
            if ($typeLabel !== '') {
                $parts[] = $typeLabel;
            }
        }

        $propertyDesc = implode(' ', $parts);

        $locationParts = [];

        if ($street !== null && trim($street) !== '') {
            $locationParts[] = 'on ' . $this->cleanStreetForTitle($street);
        }

        if (trim($city) !== '' && $city !== 'Unknown') {
            $locationParts[] = 'in ' . trim($city);
        }

        $location = implode(' ', $locationParts);

        if ($propertyDesc !== '' && $location !== '') {
            return $propertyDesc . ' ' . $location;
        }

        if ($propertyDesc !== '') {
            return $propertyDesc;
        }

        if ($location !== '') {
            return 'Property ' . $location;
        }

        return 'Property Listing';
    }

    /**
     * Check whether an AI-returned title already follows the structured format.
     *
     * Looks for natural language patterns like "Room", "in [City]", "on [Street]".
     * Rejects titles that still contain marketing fluff.
     */
    private function isStructuredTitle(string $title): bool
    {
        if ($title === '') {
            return false;
        }

        $hasLocation = preg_match('/\b(in|on)\s+[A-ZĄĆĘŁŃÓŚŹŻ][a-ząćęłńóśźż]+/u', $title);

        if (! $hasLocation) {
            return false;
        }

        $lower = mb_strtolower($title);
        if (preg_match('/okazja|pilne|super\s*oferta|mega|hot|!!!|bez\s*prowizji/iu', $lower)) {
            return false;
        }

        return true;
    }

    /**
     * Strip the "ul." / "ulica" prefix from a street name for title use.
     */
    private function cleanStreetForTitle(string $street): string
    {
        return trim(preg_replace('/^(?:ulica|ul\.?)\s*/iu', '', trim($street)));
    }
}
