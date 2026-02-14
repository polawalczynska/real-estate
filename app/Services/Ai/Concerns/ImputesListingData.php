<?php

declare(strict_types=1);

namespace App\Services\Ai\Concerns;

use App\Enums\PropertyType;

/**
 * Deterministic safety-net imputation for rooms and property type.
 *
 * When the AI returns null/0 for rooms or "unknown" for type,
 * regex-based analysis of Polish real-estate text fills the gap.
 */
trait ImputesListingData
{
    /**
     * Infer room count from title, description, or area.
     *
     * Strategy (ordered by confidence):
     *  1. Polish room patterns: "2-pokojowe", "3 pokoje", "4 pok."
     *  2. Keywords: "kawalerka" / "studio" = 1 room.
     *  3. Area-based estimation as last resort.
     */
    private function imputeRoomsFromText(string $title, string $description, float $areaM2): int
    {
        $text = mb_strtolower($title . ' ' . $description);

        if (preg_match('/(\d+)\s*[-–]?\s*(?:pokojow|pokoi|pokój|pok\.?|room)/iu', $text, $m)) {
            $rooms = (int) $m[1];
            if ($rooms >= 1 && $rooms <= 20) {
                return $rooms;
            }
        }

        if (preg_match('/kawalerk|studio/iu', $text)) {
            return 1;
        }

        if ($areaM2 > 0) {
            return match (true) {
                $areaM2 <= 35  => 1,
                $areaM2 <= 55  => 2,
                $areaM2 <= 80  => 3,
                $areaM2 <= 120 => 4,
                default        => 5,
            };
        }

        return 0;
    }

    /**
     * Infer property type from title and description.
     *
     * Maps Polish real-estate vocabulary to PropertyType enum values.
     */
    private function imputeTypeFromText(string $title, string $description): string
    {
        $text = mb_strtolower($title . ' ' . $description);

        return match (true) {
            (bool) preg_match('/penthouse/iu', $text)                           => PropertyType::PENTHOUSE->value,
            (bool) preg_match('/loft/iu', $text)                                => PropertyType::LOFT->value,
            (bool) preg_match('/willa|villa/iu', $text)                         => PropertyType::VILLA->value,
            (bool) preg_match('/kawalerk|studio/iu', $text)                     => PropertyType::STUDIO->value,
            (bool) preg_match('/szeregowiec|bliźniak|townhouse/iu', $text)      => PropertyType::TOWNHOUSE->value,
            (bool) preg_match('/\bdom\b|house/iu', $text)                       => PropertyType::HOUSE->value,
            (bool) preg_match('/apartament|mieszkani|blok|kamienica/iu', $text)  => PropertyType::APARTMENT->value,
            default                                                             => PropertyType::UNKNOWN->value,
        };
    }
}
