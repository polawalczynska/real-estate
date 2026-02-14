<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Concerns\HandlesDataCleaning;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uses JSON-LD for structured fields (price, area, rooms, city, type) and
 * DOM-based `<picture>` extraction for images. This is the deterministic
 * step — no AI involved.
 */
final class HtmlExtractorService
{
    use HandlesDataCleaning;

    private const MAX_HTML_BYTES    = 500_000;
    private const MAX_IMAGES        = 15;
    private const MIN_IMAGE_SRC_LEN = 15;
    private const MAX_LABEL_LEN     = 100;

    /**
     * Uses JSON-LD for structured fields and DOM for image extraction.
     * Returns null if no usable JSON-LD is found.
     */
    public function extractJsonLd(string $html): ?array
    {
        $graphNode = $this->findListingGraphNode($html);

        if ($graphNode === null) {
            return null;
        }

        $price    = $this->parseJsonLdPrice($graphNode);
        $currency = (string) data_get($graphNode, 'offers.priceCurrency', 'PLN');
        $areaM2   = $this->parseJsonLdArea($graphNode);
        $rooms    = (int) data_get($graphNode, 'numberOfRooms', 0);
        $city     = (string) data_get($graphNode, 'address.addressLocality', '');
        $street   = $this->parseJsonLdStreet($graphNode);
        $type     = $this->parseJsonLdPropertyType($graphNode);
        $images   = $this->extractImagesFromDom($html);

        $url        = (string) data_get($graphNode, 'url', '');
        $externalId = $this->extractExternalIdFromUrl($url);

        Log::debug('JSON-LD data extracted', [
            'street' => $street, 'city' => $city,
            'price'  => $price,  'area_m2' => $areaM2, 'rooms' => $rooms,
        ]);

        return [
            'title'       => (string) data_get($graphNode, 'name', 'Property Listing'),
            'description' => (string) data_get($graphNode, 'description', ''),
            'price'       => $price,
            'currency'    => $currency,
            'city'        => $city,
            'street'      => $street,
            'rooms'       => $rooms,
            'area_m2'     => $areaM2,
            'type'        => $type,
            'external_id' => $externalId,
            'images'      => $images,
            'url'         => $url,
            'json_ld_raw' => $graphNode,
        ];
    }

    private function loadDom(string $html): DOMXPath
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE,
        );
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function truncateHtml(string $html): string
    {
        return strlen($html) > self::MAX_HTML_BYTES
            ? substr($html, 0, self::MAX_HTML_BYTES)
            : $html;
    }


    private function extractImagesFromDom(string $html): array
    {
        try {
            $xpath  = $this->loadDom($this->truncateHtml($html));
            $images = $this->pluckImages($xpath);
            unset($xpath);

            $unique = [];
            $seen   = [];
            foreach ($images as $img) {
                $url = $img['url'] ?? '';
                if ($url !== '' && ! isset($seen[$url])) {
                    $seen[$url] = true;
                    $unique[]   = $img;
                }
            }

            return $unique;
        } catch (Throwable $e) {
            Log::warning('HtmlExtractor: DOM image extraction failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function pluckImages(DOMXPath $xpath): array
    {
        $imageData = [];

        $nodes = $xpath->query('//picture//img | //picture//source[@srcset]');

        foreach ($nodes as $node) {
            $src = $this->resolveImageSrc($node);

            if ($src === null || ! $this->isValidImageSrc($src)) {
                continue;
            }

            $imageData[] = ['url' => $src, 'label' => $this->resolveImageLabel($node)];

            if (count($imageData) >= self::MAX_IMAGES) {
                break;
            }
        }

        return $imageData;
    }

    private function resolveImageSrc(\DOMElement|\DOMNode $node): ?string
    {
        $src = $node->getAttribute('src')
            ?: $node->getAttribute('data-src')
            ?: $node->getAttribute('data-lazy-src')
            ?: $node->getAttribute('data-original')
            ?: $node->getAttribute('data-url')
            ?: $node->getAttribute('data-image-url');

        if (empty($src)) {
            $srcset = $node->getAttribute('srcset');
            if ($srcset !== '' && preg_match('/^([^\s,]+)/', $srcset, $m)) {
                $src = $m[1];
            }
        }

        if (empty($src)) {
            $style = $node->getAttribute('style');
            if (preg_match('/background-image:\s*url\(["\']?([^"\']+)["\']?\)/i', $style, $m)) {
                $src = $m[1];
            }
        }

        if (empty($src) && $node->nodeName === 'source') {
            $srcset = $node->getAttribute('srcset');
            if ($srcset !== '' && preg_match('/^([^\s,]+)/', $srcset, $m)) {
                $src = $m[1];
            }
        }

        if (empty($src)) {
            return null;
        }

        if (str_starts_with($src, '//')) {
            return 'https:' . $src;
        }

        if (str_starts_with($src, '/')) {
            return null;
        }

        return $src;
    }

    private function isValidImageSrc(string $src): bool
    {
        if (strlen($src) < self::MIN_IMAGE_SRC_LEN) {
            return false;
        }

        $lower = strtolower($src);

        if (str_starts_with($lower, 'data:') || str_contains($lower, 'placeholder')
            || str_contains($lower, 'icon') || str_contains($lower, 'logo')
            || str_contains($lower, 'avatar')) {
            return false;
        }

        $hasImageExtension = (bool) preg_match('/\.(jpg|jpeg|png|gif|webp|svg)(\?|$)/i', $src);

        return $hasImageExtension
            || str_contains($src, 'apollo.olxcdn.com')
            || str_contains($src, 'otodom.pl')
            || str_contains($src, 'image')
            || str_contains($src, 'photo')
            || str_contains($src, 'img');
    }

    private function resolveImageLabel(\DOMElement|\DOMNode $node): string
    {
        $alt   = $node->getAttribute('alt') ?: '';
        $title = $node->getAttribute('title') ?: '';
        $aria  = $node->getAttribute('aria-label') ?: '';

        $parentLabel = '';
        if ($node->parentNode instanceof \DOMElement) {
            $parentText = trim($node->parentNode->textContent ?? '');
            if ($parentText !== '' && strlen($parentText) < self::MAX_LABEL_LEN) {
                $parentLabel = $parentText;
            }
        }

        $combined = trim(implode(' ', array_filter([$alt, $title, $aria, $parentLabel])));

        return $combined !== '' ? $combined : 'Property image';
    }

    private function findListingGraphNode(string $html): ?array
    {
        if (! preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $jsonString) {
            $decoded = json_decode(trim($jsonString), true);

            if (! is_array($decoded)) {
                continue;
            }

            if ($this->isListingNode($decoded)) {
                return $decoded;
            }

            $graph = $decoded['@graph'] ?? [];
            if (! is_array($graph)) {
                continue;
            }

            foreach ($graph as $node) {
                if (is_array($node) && $this->isListingNode($node)) {
                    return $node;
                }
            }
        }

        return null;
    }

    private function isListingNode(array $node): bool
    {
        $types        = (array) ($node['@type'] ?? []);
        $listingTypes = ['Product', 'Apartment', 'Residence', 'House', 'SingleFamilyResidence', 'RealEstateListing'];

        foreach ($types as $type) {
            if (in_array($type, $listingTypes, true)) {
                return true;
            }
        }

        return false;
    }

    private function parseJsonLdPrice(array $node): float
    {
        $raw = data_get($node, 'offers.price')
            ?? data_get($node, 'offers.0.price')
            ?? data_get($node, 'price')
            ?? '0';

        return (float) preg_replace('/[^\d.]/', '', (string) $raw);
    }

    private function parseJsonLdArea(array $node): float
    {
        $properties = data_get($node, 'additionalProperty', []);

        if (is_array($properties)) {
            foreach ($properties as $prop) {
                $name = data_get($prop, 'name', '');
                if (preg_match('/powierzchnia|area|floor\s*area/iu', $name)) {
                    $value = (string) data_get($prop, 'value', '0');

                    return (float) str_replace(',', '.', preg_replace('/[^\d.,]/', '', $value));
                }
            }
        }

        $floorSize = data_get($node, 'floorSize.value')
            ?? data_get($node, 'floorSize')
            ?? null;

        if ($floorSize !== null) {
            return (float) str_replace(',', '.', preg_replace('/[^\d.,]/', '', (string) $floorSize));
        }

        return 0.0;
    }

    private function parseJsonLdStreet(array $node): ?string
    {
        $raw = data_get($node, 'address.streetAddress');

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return $this->stripStreetPrefix((string) $raw);
    }

    private function parseJsonLdPropertyType(array $node): ?string
    {
        $properties = data_get($node, 'additionalProperty', []);

        if (is_array($properties)) {
            foreach ($properties as $prop) {
                if (preg_match('/rodzaj\s*zabudowy|building\s*type/iu', (string) data_get($prop, 'name', ''))) {
                    $value = mb_strtolower(trim((string) data_get($prop, 'value', '')));

                    return match (true) {
                        str_contains($value, 'apartament') => 'apartment',
                        str_contains($value, 'blok')       => 'apartment',
                        str_contains($value, 'kamienica')   => 'apartment',
                        str_contains($value, 'loft')        => 'loft',
                        str_contains($value, 'dom')         => 'house',
                        str_contains($value, 'willa')       => 'villa',
                        str_contains($value, 'szeregowiec') => 'townhouse',
                        str_contains($value, 'penthouse')   => 'penthouse',
                        default                             => 'apartment',
                    };
                }
            }
        }

        $types   = (array) ($node['@type'] ?? []);
        $typeMap = [
            'Apartment'             => 'apartment',
            'House'                 => 'house',
            'SingleFamilyResidence' => 'house',
            'Residence'             => 'apartment',
        ];

        foreach ($types as $type) {
            if (isset($typeMap[$type])) {
                return $typeMap[$type];
            }
        }

        return 'apartment';
    }

    private function extractExternalIdFromUrl(string $url): ?string
    {
        if (preg_match('/[-\/]([A-Za-z0-9]{6,12})(?:[?#]|$)/', $url, $m)) {
            return 'otodom_' . $m[1];
        }

        if ($url !== '') {
            return 'otodom_' . md5($url);
        }

        return null;
    }
}
