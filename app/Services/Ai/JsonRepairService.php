<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Extracts, repairs, and cleans JSON from potentially truncated or
 * malformed Claude API responses.
 */
final class JsonRepairService
{
    public function extract(string $content): ?array
    {
        $original = $content;
        $content = preg_replace('/```json\s*/i', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);

        [$jsonString, $braceCount, $bracketCount, $startPos] = $this->findJsonObject($content);

        if ($jsonString !== '') {
            $json = json_decode($jsonString, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        if ($startPos !== -1 && ($braceCount > 0 || $bracketCount > 0)) {
            $incomplete = substr($content, $startPos);

            Log::warning('JsonRepair: Attempting repair of truncated JSON', [
                'original_length'  => strlen($original),
                'missing_braces'   => $braceCount,
                'missing_brackets' => $bracketCount,
            ]);

            $repaired = $this->repairTruncated($incomplete, $braceCount, $bracketCount);
            $json = json_decode($repaired, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->cleanImageUrls($json);
            }

            $repair2 = $incomplete;
            $repair2 = preg_replace('/,\s*"[^"]*$/m', '', $repair2);
            $repair2 = preg_replace('/\[\s*"[^"]*$/m', '[]', $repair2);
            $repair2 = preg_replace('/:\s*"[^"]*$/m', ': ""', $repair2);
            $repair2 .= str_repeat(']', max(0, $bracketCount))
                      . str_repeat('}', max(0, $braceCount));

            $json = json_decode($repair2, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->cleanImageUrls($json);
            }

            Log::warning('JsonRepair: Both repair strategies failed', [
                'json_error'    => json_last_error_msg(),
                'repaired_tail' => substr($repaired, -200),
            ]);
        }

        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        Log::error('JsonRepair: Could not extract JSON', [
            'content_preview' => substr($content, 0, 500),
            'content_length'  => strlen($content),
            'json_error'      => json_last_error_msg(),
        ]);

        return null;
    }

    private function findJsonObject(string $content): array
    {
        $braceCount   = 0;
        $bracketCount = 0;
        $startPos     = -1;
        $inString     = false;
        $escapeNext   = false;

        for ($i = 0, $len = strlen($content); $i < $len; $i++) {
            $char = $content[$i];

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }
            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }
            if ($char === '"') {
                $inString = ! $inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                if ($startPos === -1) {
                    $startPos = $i;
                }
                $braceCount++;
            } elseif ($char === '}') {
                $braceCount--;
                if ($braceCount === 0 && $startPos !== -1) {
                    return [substr($content, $startPos, $i - $startPos + 1), 0, 0, $startPos];
                }
            } elseif ($char === '[') {
                $bracketCount++;
            } elseif ($char === ']') {
                $bracketCount--;
            }
        }

        return ['', $braceCount, $bracketCount, $startPos];
    }

    private function repairTruncated(string $json, int $missingBraces, int $missingBrackets): string
    {
        $json = preg_replace('/[\x00-\x1F\x7F]/', ' ', $json);

        $closers = str_repeat(']', max(0, $missingBrackets))
                 . str_repeat('}', max(0, $missingBraces));

        for ($i = strlen($json) - 1; $i > 0; $i--) {
            if ($json[$i] !== ',') {
                continue;
            }

            $candidate = substr($json, 0, $i) . $closers;
            $decoded   = json_decode($candidate, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $candidate;
            }
        }

        return $json . $closers;
    }

    private function cleanImageUrls(array $json): array
    {
        $valid = static fn (mixed $url): bool =>
            is_string($url)
            && strlen($url) > 20
            && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))
            && filter_var($url, FILTER_VALIDATE_URL) !== false;

        if (isset($json['images']) && is_array($json['images'])) {
            $json['images'] = array_values(array_filter($json['images'], $valid));
        }

        if (isset($json['selected_images']['gallery_urls']) && is_array($json['selected_images']['gallery_urls'])) {
            $json['selected_images']['gallery_urls'] = array_values(
                array_filter($json['selected_images']['gallery_urls'], $valid),
            );
        }

        if (isset($json['selected_images']['hero_url']) && ! $valid($json['selected_images']['hero_url'])) {
            $json['selected_images']['hero_url'] = null;
        }

        return $json;
    }
}
