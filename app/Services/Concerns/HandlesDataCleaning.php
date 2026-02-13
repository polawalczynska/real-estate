<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Reusable text-cleaning and data-formatting utilities.
 *
 * Shared across parsers and normalisation services to ensure
 * consistent text sanitisation throughout the pipeline.
 */
trait HandlesDataCleaning
{
    /**
     * Remove common Polish street prefixes ("ul.", "ulica") from a street name.
     */
    private function stripStreetPrefix(?string $street): ?string
    {
        if ($street === null || trim($street) === '') {
            return null;
        }

        $cleaned = preg_replace('/^(ulica\s+|ul\.?\s*)/iu', '', trim($street));

        return trim($cleaned ?? $street) ?: null;
    }

    /**
     * Recursively clean invalid UTF-8 sequences and control characters.
     *
     * Prevents json_encode failures when passing scraped text to the AI API.
     */
    private function cleanUtf8(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map([$this, 'cleanUtf8'], $data);
        }

        if (is_string($data)) {
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $data);
                if ($converted !== false) {
                    $data = $converted;
                }
            }

            $data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
            $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $data);

            return $data ?: '';
        }

        return $data;
    }
}
