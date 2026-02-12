<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Reusable image-URL validation shared across services.
 *
 * Rejects placeholders, icons, logos, data URIs, and URLs that are
 * too short to be genuine property photographs.
 */
trait ValidatesImageUrls
{
    private const MIN_IMAGE_URL_LENGTH = 20;

    private const BLOCKED_URL_KEYWORDS = [
        'placeholder',
        'icon',
        'logo',
        'avatar',
        'data:',
        'svg',
        'favicon',
    ];

    private function isValidImageUrl(string $url): bool
    {
        if ($url === '' || strlen($url) < self::MIN_IMAGE_URL_LENGTH) {
            return false;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            return false;
        }

        $lower = strtolower($url);

        foreach (self::BLOCKED_URL_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return false;
            }
        }

        return true;
    }
}
