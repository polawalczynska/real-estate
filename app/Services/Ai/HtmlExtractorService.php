<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Concerns\HandlesDataCleaning;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 1 extractor: pulls structured content and image metadata from raw listing HTML.
 *
 * Two extraction modes:
 *  1. `extractJsonLd()` — deterministic structured data for skeleton creation and fingerprinting.
 *  2. `extractContent()` — condensed DOM plucking to reduce AI token usage by 60–80%.
 *
 * All constants (limits, sizes) are class-level for easy tuning.
 * Street prefixes are stripped via the HandlesDataCleaning trait.
 */
final class HtmlExtractorService
{
    use HandlesDataCleaning;
    private const MAX_HTML_BYTES      = 500_000;
    private const MAX_IMAGES          = 15;
    private const MAX_TEXT_CHARS      = 6_000;
    private const MAX_TITLE_LENGTH    = 200;
    private const FALLBACK_CHARS      = 10_000;
    private const MIN_HTML_LENGTH     = 50;
    private const MIN_IMAGE_SRC_LEN   = 15;
    private const MAX_SPEC_TEXT_LEN   = 100;
    private const MIN_DESC_TEXT_LEN   = 50;
    private const MAX_DESC_TEXT_LEN   = 500;
    private const MAX_LOCATION_LEN    = 150;
    private const MIN_FALLBACK_LEN    = 100;
    private const MAX_FALLBACK_EXTRACT = 1_000;
    private const MAX_LABEL_LEN       = 100;
    private const MAX_LOCATION_NODE_LEN = 200;

    private array $lastExtractedImages = [];

    /**
     * Condense full-page HTML into a compact text representation for AI normalisation.
     *
     * Plucks title, price, specs, description, location, and images from the DOM,
     * then joins them into a token-efficient string. Falls back to raw truncation
     * if DOM parsing fails.
     */
    public function extractContent(string $html): string
    {
        $html = $this->truncateHtml($html);

        try {
            $xpath    = $this->loadDom($html);
            $sections = [];

            $this->pluckTitle($xpath, $sections);
            $this->pluckPrice($xpath, $sections);
            $this->pluckSpecs($xpath, $sections);
            $this->pluckDescription($xpath, $sections);
            $this->pluckLocation($xpath, $sections);
            $this->lastExtractedImages = $this->pluckImages($xpath);
            $this->pluckFallbackContent($xpath, $sections);

            unset($xpath);

            $condensed = implode("\n\n", array_unique($sections));
            $condensed = trim(preg_replace('/\s+/', ' ', $condensed));

            if (strlen($condensed) > self::MAX_TEXT_CHARS) {
                $condensed = substr($condensed, 0, self::MAX_TEXT_CHARS) . '...';
            }

            return $condensed;
        } catch (Throwable $e) {
            Log::warning('HtmlExtractor: DOM plucking failed', ['error' => $e->getMessage()]);

            return substr($html, 0, self::FALLBACK_CHARS) . '...';
        }
    }

    public function getLastExtractedImages(): array
    {
        return $this->lastExtractedImages;
    }

    /**
     * Extract structured metadata for fingerprint calculation.
     *
     * @return array{price: float, area_m2: float, rooms: int, city: string, street: string|null}
     */
    public function extractStructuredMetadata(string $html): array
    {
        $meta = [
            'price'   => 0.0,
            'area_m2' => 0.0,
            'rooms'   => 0,
            'city'    => '',
            'street'  => null,
        ];

        if ($html === '' || strlen($html) < self::MIN_HTML_LENGTH) {
            return $meta;
        }

        try {
            $xpath = $this->loadDom($this->truncateHtml($html));

            $meta['price']   = $this->extractPrice($xpath);
            $meta['area_m2'] = $this->extractArea($xpath);
            $meta['rooms']   = $this->extractRooms($xpath);

            [$city, $street] = $this->extractLocation($xpath);
            $meta['city']    = $city;
            $meta['street']  = $street;

            unset($xpath);
        } catch (Throwable) {
            // Zero-value defaults proceed to AI for full extraction.
        }

        return $meta;
    }

    /**
     * Extract the listing title from HTML.
     *
     * Used by providers for quick title extraction without AI.
     */
    public function extractTitle(string $html): string
    {
        try {
            $xpath = $this->loadDom($this->truncateHtml($html));

            $nodes = $xpath->query('//h1 | //title');
            foreach ($nodes as $node) {
                $text = trim(strip_tags($node->textContent));
                if ($text !== '' && strlen($text) < self::MAX_TITLE_LENGTH) {
                    return $text;
                }
            }
        } catch (Throwable) {
            // Fall through to default.
        }

        return 'Property Listing';
    }

    /**
     * Extract structured listing data from JSON-LD embedded in HTML.
     *
     * Uses JSON-LD for structured fields and DOM for image extraction.
     * Returns null if no usable JSON-LD is found.
     *
     * @return array{
     *     title: string,
     *     description: string,
     *     price: float,
     *     currency: string,
     *     city: string,
     *     street: string|null,
     *     rooms: int,
     *     area_m2: float,
     *     type: string|null,
     *     external_id: string|null,
     *     images: string[],
     *     url: string,
     *     json_ld_raw: array,
     * }|null
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

        // Use DOM-based image extraction for better coverage
        $images = $this->extractImagesFromDom($html);

        $url        = (string) data_get($graphNode, 'url', '');
        $externalId = $this->extractExternalIdFromUrl($url);

        $extractedData = [
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

        Log::debug('JSON-LD data extracted', [
            'street'  => $street,
            'city'    => $city,
            'price'   => $price,
            'area_m2' => $areaM2,
            'rooms'   => $rooms,
        ]);

        return $extractedData;
    }

    // ─── DOM Helpers ────────────────────────────────────────────

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

    // ─── Numeric Extraction ─────────────────────────────────────

    private function extractPrice(DOMXPath $xpath): float
    {
        $nodes = $xpath->query(
            '//*[@data-price] | //*[@class[contains(., "price")]] | '
            . '//*[contains(text(), "PLN") or contains(text(), "zł")]',
        );

        foreach ($nodes as $node) {
            $dataPrice = $node->getAttribute('data-price');
            if ($dataPrice !== '') {
                $value = $this->parseNumericValue($dataPrice);
                if ($value > 1_000) {
                    return $value;
                }
            }
        }

        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('/[\d\s,.]+/', $text, $m)) {
                $value = $this->parseNumericValue($m[0]);
                if ($value > 1_000) {
                    return $value;
                }
            }
        }

        return 0.0;
    }

    private function extractArea(DOMXPath $xpath): float
    {
        $nodes = $xpath->query(
            '//*[contains(text(), "m²") or contains(text(), "m2") or '
            . '@class[contains(., "area")] or @data-testid[contains(., "area")]]',
        );

        foreach ($nodes as $node) {
            $text = trim($node->textContent);

            if (preg_match('/([\d]+[.,]?\d*)\s*m[²2]/iu', $text, $m)) {
                $area = (float) str_replace(',', '.', $m[1]);
                if ($area > 5 && $area < 10_000) {
                    return $area;
                }
            }
        }

        return 0.0;
    }

    private function extractRooms(DOMXPath $xpath): int
    {
        $nodes = $xpath->query(
            '//*[contains(text(), "pokoi") or contains(text(), "pokój") or '
            . 'contains(text(), "pok.") or contains(text(), "room") or '
            . '@class[contains(., "room")] or @data-testid[contains(., "room")]]',
        );

        foreach ($nodes as $node) {
            $text = trim($node->textContent);

            if (preg_match('/(\d+)\s*(?:pokoi|pokój|pok\.|room)/iu', $text, $m)) {
                $rooms = (int) $m[1];
                if ($rooms >= 1 && $rooms <= 20) {
                    return $rooms;
                }
            }

            if (preg_match('/(?:pokoi|room)[^\d]*(\d+)/iu', $text, $m)) {
                $rooms = (int) $m[1];
                if ($rooms >= 1 && $rooms <= 20) {
                    return $rooms;
                }
            }
        }

        return 0;
    }

    /**
     * @return array{0: string, 1: string|null}  [city, street]
     */
    private function extractLocation(DOMXPath $xpath): array
    {
        $city   = '';
        $street = null;

        $nodes = $xpath->query(
            '//*[@class[contains(., "location") or contains(., "address") or contains(., "breadcrumb")] '
            . 'or contains(text(), "ul.")]',
        );

        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if ($text === '' || strlen($text) > self::MAX_LOCATION_NODE_LEN) {
                continue;
            }

            if ($street === null && preg_match('/ul\.\s*([^\d,;]+)/iu', $text, $m)) {
                $street = trim($m[1]);
            }

            if ($city === '' && preg_match('/^([A-ZŁŚĆŹŻĄĘÓŃ][a-ząćęłńóśźż]+)/u', $text, $m)) {
                $candidate = trim($m[1]);
                if (mb_strlen($candidate) >= 3) {
                    $city = $candidate;
                }
            }
        }

        if ($city === '') {
            $metaNodes = $xpath->query(
                '//meta[@name="geo.placename"]/@content | //meta[@property="og:locality"]/@content',
            );
            foreach ($metaNodes as $node) {
                $value = trim($node->nodeValue ?? '');
                if ($value !== '') {
                    $city = $value;
                    break;
                }
            }
        }

        return [$city, $street];
    }

    private function parseNumericValue(string $raw): float
    {
        $clean = preg_replace('/[\x{00A0}\s\x{202F}PLNzł]/u', '', $raw);

        if (preg_match('/,\d{2}$/', $clean)) {
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }

        return (float) preg_replace('/[^\d.]/', '', $clean);
    }

    // ─── Pluck methods (for AI content condensation) ────────────

    private function pluckTitle(DOMXPath $xpath, array &$sections): void
    {
        $nodes = $xpath->query('//h1 | //title | //*[@class[contains(., "title") or contains(., "heading")]]');
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if ($text !== '' && strlen($text) < self::MAX_TITLE_LENGTH) {
                $sections[] = "Title: {$text}";

                return;
            }
        }
    }

    private function pluckPrice(DOMXPath $xpath, array &$sections): void
    {
        $nodes = $xpath->query('//*[@class[contains(., "price")] or @data-price or contains(text(), "PLN") or contains(text(), "zł")]');
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if (preg_match('/\d[\d\s,]*\s*(?:PLN|zł)/iu', $text)) {
                $sections[] = "Price: {$text}";

                return;
            }
        }
    }

    private function pluckSpecs(DOMXPath $xpath, array &$sections): void
    {
        $nodes = $xpath->query('//*[@class[contains(., "spec") or contains(., "detail") or contains(., "feature")] or contains(text(), "m²") or contains(text(), "pokoi")]');
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if ($text !== '' && strlen($text) < self::MAX_SPEC_TEXT_LEN) {
                $sections[] = $text;
            }
        }
    }

    private function pluckDescription(DOMXPath $xpath, array &$sections): void
    {
        $nodes = $xpath->query('//meta[@name="description"]/@content | //*[@class[contains(., "description") or contains(., "opis")]] | //p[position() <= 5]');
        $count = 0;
        foreach ($nodes as $node) {
            $text = trim($node->textContent ?? $node->nodeValue ?? '');
            if (strlen($text) > self::MIN_DESC_TEXT_LEN && strlen($text) < self::MAX_DESC_TEXT_LEN) {
                $sections[] = "Description: {$text}";
                if (++$count >= 3) {
                    return;
                }
            }
        }
    }

    private function pluckLocation(DOMXPath $xpath, array &$sections): void
    {
        $nodes = $xpath->query('//*[@class[contains(., "location") or contains(., "address") or contains(., "city")] or contains(text(), "ul.")]');
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if ($text !== '' && strlen($text) < self::MAX_LOCATION_LEN) {
                $sections[] = "Location: {$text}";

                return;
            }
        }
    }

    private function pluckImages(DOMXPath $xpath): array
    {
        $imageData = [];

        $nodes = $xpath->query('
            //img[@src] | //img[@data-src] | //img[@data-lazy-src] |
            //img[@data-original] | //img[@data-url] |
            //*[@style[contains(., "background-image")]] |
            //*[@data-image] |
            //*[@class[contains(., "gallery") or contains(., "image") or contains(., "photo") or contains(., "carousel") or contains(., "slider")]]//img |
            //*[@id[contains(., "gallery") or contains(., "image") or contains(., "photo")]]//img |
            //picture//img |
            //source[@srcset]
        ');

        foreach ($nodes as $node) {
            $src = $this->resolveImageSrc($node);

            if ($src === null || ! $this->isValidImageSrc($src)) {
                continue;
            }

            $label       = $this->resolveImageLabel($node);
            $imageData[] = ['url' => $src, 'label' => $label];

            if (count($imageData) >= self::MAX_IMAGES) {
                break;
            }
        }

        return $imageData;
    }

    private function pluckFallbackContent(DOMXPath $xpath, array &$sections): void
    {
        if (count($sections) >= 5) {
            return;
        }

        $main = $xpath->query('//main | //article | //*[@class[contains(., "content") or contains(., "listing")]]');
        if ($main->length > 0) {
            $text = trim($main->item(0)->textContent ?? '');
            if (strlen($text) > self::MIN_FALLBACK_LEN) {
                $sections[] = substr($text, 0, self::MAX_FALLBACK_EXTRACT);
            }
        }
    }

    // ─── Image Source Resolution ────────────────────────────────

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
            $src = 'https:' . $src;
        } elseif (str_starts_with($src, '/')) {
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
        $hasImageDomain    = str_contains($src, 'apollo.olxcdn.com')
            || str_contains($src, 'otodom.pl')
            || str_contains($src, 'image')
            || str_contains($src, 'photo')
            || str_contains($src, 'img')
            || $hasImageExtension;

        return $hasImageDomain || $hasImageExtension;
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

    // ─── DOM-based Image Extraction (for extractJsonLd) ─────────

    /**
     * Extract image URLs from HTML using DOM parsing.
     *
     * @return string[]
     */
    private function extractImagesFromDom(string $html): array
    {
        try {
            $xpath  = $this->loadDom($this->truncateHtml($html));
            $images = $this->pluckImages($xpath);
            unset($xpath);

            return array_values(array_unique(
                array_map(static fn (array $img): string => $img['url'], $images),
            ));
        } catch (Throwable $e) {
            Log::warning('HtmlExtractor: DOM image extraction failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    // ─── JSON-LD Graph Traversal ───────────────────────────────

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
        $types = (array) ($node['@type'] ?? []);

        $listingTypes = ['Product', 'Apartment', 'Residence', 'House', 'SingleFamilyResidence', 'RealEstateListing'];

        foreach ($types as $type) {
            if (in_array($type, $listingTypes, true)) {
                return true;
            }
        }

        return false;
    }

    // ─── JSON-LD Field Parsers ──────────────────────────────────

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
        // Prefer portal-specific "Rodzaj zabudowy" (more granular than schema @type)
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

        // Fallback: schema.org @type
        $types = (array) ($node['@type'] ?? []);

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
        // Otodom URLs end with an ID like: /oferta/tytul-oferty-ID4zZIU
        if (preg_match('/[-\/]([A-Za-z0-9]{6,12})(?:[?#]|$)/', $url, $m)) {
            return 'otodom_' . $m[1];
        }

        if ($url !== '') {
            return 'otodom_' . md5($url);
        }

        return null;
    }
}
