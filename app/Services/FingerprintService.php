<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Single source of truth for semantic fingerprint calculation.
 *
 * A fingerprint is an MD5 hash of normalised property attributes
 * (city, street, price, area, rooms).  Two listings describing the
 * same physical property — even from different platforms — will
 * produce an identical fingerprint.
 *
 * Normalisation rules (prevent false-negatives):
 *   • City / street: trimmed, lowercased, fallback to known tokens.
 *   • Price: rounded to nearest 1 000 (absorbs minor formatting diffs).
 *   • Area:  rounded to nearest integer m².
 *   • Rooms: cast to int (0 when missing).
 */
final class FingerprintService
{
    /** Round prices to the nearest 1 000 to absorb formatting differences. */
    private const PRICE_ROUNDING_PRECISION = -3;

    public static function calculate(
        string $city,
        ?string $street,
        float $price,
        float $areaM2,
        int $rooms,
    ): string {
        return md5(implode('|', [
            self::normaliseText($city, 'unknown'),
            self::normaliseText($street, 'unknown-street'),
            (string) self::roundPrice($price),
            (string) self::roundArea($areaM2),
            (string) max(0, $rooms),
        ]));
    }

    private static function normaliseText(?string $value, string $fallback): string
    {
        $clean = mb_strtolower(trim($value ?? ''));

        return $clean !== '' ? $clean : $fallback;
    }

    private static function roundPrice(float $price): float
    {
        return round($price, self::PRICE_ROUNDING_PRECISION);
    }

    private static function roundArea(float $area): int
    {
        return (int) round($area);
    }
}
