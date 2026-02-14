<?php

declare(strict_types=1);

namespace App\Services\Ai\Concerns;

/**
 * Image URL assembly, curation extraction, and prompt-section formatting.
 *
 * Merges images from AI response, raw data, and DOM extraction into a
 * deduplicated, validated list. Filters out logos, agency graphics, and
 * non-property images by label and URL keywords.
 *
 * Expects the consuming class to provide `isValidImageUrl(string): bool`
 * (from ValidatesImageUrls trait).
 */
trait AssemblesImages
{
    private const LOGO_KEYWORDS = ['logo', 'brand', 'watermark', 'agency', 'company', 'firm', 'biuro'];

    private function extractAiCuration(array $normalized): ?array
    {
        $selectedImages = $normalized['selected_images']
            ?? $normalized['image_curation']
            ?? null;

        if (! is_array($selectedImages)) {
            return null;
        }

        return [
            'hero_url'     => $selectedImages['hero_url'] ?? null,
            'gallery_urls' => $selectedImages['gallery_urls'] ?? [],
        ];
    }

    private function assembleImageUrls(array $normalized, array $rawData, array $rawDataArray): array
    {
        $sources = [
            $normalized['images'] ?? [],
            $rawDataArray['images'] ?? $rawData['images'] ?? [],
            $rawDataArray['extracted_images'] ?? [],
        ];

        $candidates = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            foreach ($source as $img) {
                $url = $this->extractUrlFromImage($img);
                if ($url !== null) {
                    $candidates[] = $url;
                }
            }
        }

        return array_values(array_filter(
            array_unique($candidates),
            fn (string $url): bool => $this->isValidImageUrl($url),
        ));
    }

    private function buildImageSection(array $images): string
    {
        if ($images === []) {
            return '';
        }

        $lines = [];
        $index = 0;

        foreach ($images as $img) {
            $url   = is_array($img) ? ($img['url'] ?? '') : (string) $img;
            $label = is_array($img) ? ($img['label'] ?? 'Property image') : 'Property image';

            if ($url === '' || $this->isLogoImage($url, $label) || ! $this->isValidImageUrl($url)) {
                continue;
            }

            $index++;
            $lines[] = "{$index}. {$url} (Label: {$label})";
        }

        if ($lines === []) {
            return '';
        }

        return "\n\n=== PROPERTY IMAGES ===\n" . implode("\n", $lines) . "\n=== END IMAGES ===";
    }

    private function extractUrlFromImage(mixed $item): ?string
    {
        if (is_string($item)) {
            return $item;
        }

        if (is_array($item)) {
            if (isset($item['url']) && is_string($item['url'])) {
                return $item['url'];
            }
            if (isset($item[0]) && is_string($item[0])) {
                return $item[0];
            }
        }

        return null;
    }

    private function isLogoImage(string $url, string $label): bool
    {
        $labelLower = mb_strtolower($label);
        $urlLower   = mb_strtolower($url);

        foreach (self::LOGO_KEYWORDS as $keyword) {
            if (str_contains($labelLower, $keyword) || str_contains($urlLower, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
