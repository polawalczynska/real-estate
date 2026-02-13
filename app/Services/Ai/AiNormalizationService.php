<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiNormalizerInterface;
use App\DTOs\ListingDTO;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Exceptions\AiNormalizationException;
use App\Services\Ai\Concerns\InteractsWithClaude;
use App\Services\Concerns\HandlesDataCleaning;
use App\Services\Concerns\ValidatesImageUrls;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 2 of the two-step pipeline: AI-driven normalisation via Claude.
 *
 * Receives pre-extracted JSON-LD data from Phase 1 and sends it to Claude
 * for consistency normalisation (translation, description cleaning, type
 * mapping, image curation).
 *
 * If the AI returns null/0 for fields like `rooms` or `type`, a local
 * "safety net" imputation step analyses the title and description to
 * fill the gaps deterministically.
 *
 * If the entire AI call fails, falls back to raw JSON-LD data so the
 * listing is still saved (marked UNVERIFIED).
 */
final class AiNormalizationService implements AiNormalizerInterface
{
    use InteractsWithClaude;
    use HandlesDataCleaning;
    use ValidatesImageUrls;

    public function __construct(
        private readonly JsonRepairService $jsonRepair,
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {}

    /**
     * Normalise pre-extracted listing data through the AI pipeline.
     *
     * @param  array<string, mixed>  $rawData  Must contain `json_ld`, `external_id`, `raw_data`.
     *
     * @throws AiNormalizationException On non-retryable API errors.
     */
    public function normalize(array $rawData): ListingDTO
    {
        $jsonLd = $rawData['json_ld'] ?? null;

        $normalized = $this->callClaudeApi($rawData);

        if ($normalized === null) {
            if ($jsonLd !== null && ($jsonLd['price'] ?? 0) > 0) {
                Log::warning('AI normalization failed — falling back to raw JSON-LD data.', [
                    'external_id' => $rawData['external_id'] ?? null,
                ]);

                return $this->mapJsonLdFallbackToDTO($jsonLd, $rawData);
            }

            throw AiNormalizationException::apiError(0, 'Claude returned no usable data.');
        }

        return $this->mapToDTO($normalized, $rawData);
    }

    // ─── Claude API ────────────────────────────────────────────────

    /**
     * Call the Claude API with automatic model fallback.
     *
     * Tries the primary model first; on 529 (overloaded) or exception,
     * retries with the configured fallback model.
     *
     * @return array<string, mixed>|null  Parsed JSON response, or null on failure.
     */
    private function callClaudeApi(array $rawData): ?array
    {
        $apiKey = $this->resolveApiKey();
        throw_if($apiKey === null, AiNormalizationException::missingApiKey());

        $prompt        = $this->buildPrompt($rawData);
        $timeout       = (int) config('services.anthropic.normalization_timeout', 30);
        $maxTokens     = (int) config('services.anthropic.normalization_max_tokens', 4096);
        $primaryModel  = $this->resolveModel(configKey: 'model', default: 'claude-haiku-4-5-20251001');
        $fallbackModel = config('services.anthropic.fallback_model', 'claude-sonnet-4-5-20250929');
        $modelsToTry   = array_values(array_unique([$primaryModel, $fallbackModel]));

        foreach ($modelsToTry as $attemptIndex => $model) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($this->claudeHeaders($apiKey))
                    ->post($this->claudeApiUrl(), [
                        'model'      => $model,
                        'max_tokens' => $maxTokens,
                        'system'     => config('ai_prompts.normalization.system'),
                        'messages'   => [['role' => 'user', 'content' => $prompt]],
                    ]);

                if (! $response->successful()) {
                    return $this->handleApiError($response, $model, $attemptIndex, $modelsToTry);
                }

                return $this->parseApiResponse($response, $model);
            } catch (AiNormalizationException $e) {
                throw $e;
            } catch (Throwable $e) {
                Log::error('Claude API exception', [
                    'error' => $e->getMessage(),
                    'model' => $model,
                ]);

                if ($attemptIndex === 0 && count($modelsToTry) > 1) {
                    continue;
                }

                return null;
            }
        }

        return null;
    }

    // ─── Prompt Building ───────────────────────────────────────────

    /**
     * Build the user prompt by interpolating JSON-LD data and image metadata.
     */
    private function buildPrompt(array $rawData): string
    {
        $jsonLd = $rawData['json_ld'] ?? null;
        $images = $jsonLd['images'] ?? $rawData['raw_data']['extracted_images'] ?? [];

        $payload = $jsonLd !== null
            ? array_diff_key($jsonLd, array_flip(['json_ld_raw']))
            : ($rawData['raw_data'] ?? $rawData);

        $payload = $this->cleanUtf8($payload);
        $images  = $this->cleanUtf8($images);

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_IGNORE')) {
            $flags |= JSON_INVALID_UTF8_IGNORE;
        }

        $jsonData = @json_encode($payload, $flags);

        if ($jsonData === false) {
            Log::error('Failed to encode payload to JSON', [
                'json_error'  => json_last_error_msg(),
                'external_id' => $rawData['external_id'] ?? null,
            ]);
            $jsonData = '{}';
        }

        $imageSection = $this->buildImageSection($images);

        return str_replace(
            ['{jsonData}', '{imageSection}'],
            [$jsonData, $imageSection],
            config('ai_prompts.normalization.user'),
        );
    }

    /**
     * Format image URLs and labels into a prompt section.
     */
    private function buildImageSection(array $images): string
    {
        if ($images === []) {
            return '';
        }

        $lines = [];
        foreach ($images as $i => $img) {
            $url   = is_array($img) ? ($img['url'] ?? '') : (string) $img;
            $label = is_array($img) ? ($img['label'] ?? 'Property image') : 'Property image';

            if ($url !== '') {
                $lines[] = ($i + 1) . '. ' . $url . " (Label: {$label})";
            }
        }

        if ($lines === []) {
            return '';
        }

        return "\n\n=== PROPERTY IMAGES ===\n" . implode("\n", $lines) . "\n=== END IMAGES ===";
    }

    // ─── API Error Handling ────────────────────────────────────────

    /**
     * Handle non-2xx API responses with structured error classification.
     *
     * @throws AiNormalizationException On rate-limit, billing, or model errors.
     */
    private function handleApiError(
        Response $response,
        string $model,
        int $attemptIndex,
        array $modelsToTry,
    ): null {
        $status       = $response->status();
        $errorBody    = $response->json();
        $errorMessage = data_get($errorBody, 'error.message', $response->body());

        Log::error('Claude API request failed', [
            'status' => $status,
            'model'  => $model,
            'error'  => $errorMessage,
        ]);

        if ($status === 429) {
            throw AiNormalizationException::rateLimited($errorMessage);
        }

        if ($status === 529) {
            if ($attemptIndex === 0 && count($modelsToTry) > 1) {
                Log::warning('Claude overloaded, trying fallback model');

                return null;
            }
            throw AiNormalizationException::overloaded($errorMessage);
        }

        if (in_array($status, [400, 404], true) && $this->isBillingOrModelError($errorMessage)) {
            throw AiNormalizationException::apiError($status, $errorMessage);
        }

        return null;
    }

    private function isBillingOrModelError(string $message): bool
    {
        return str_contains($message, 'model')
            || str_contains($message, 'credit balance')
            || str_contains($message, 'billing');
    }

    /**
     * Extract and validate JSON from a successful Claude response.
     *
     * @return array<string, mixed>|null  Parsed + UTF-8 cleaned response.
     */
    private function parseApiResponse(Response $response, string $model): ?array
    {
        $content = $response->json('content.0.text');

        if (empty($content)) {
            Log::error('Claude API returned empty content', ['model' => $model]);

            return null;
        }

        $json = $this->jsonRepair->extract($content);

        if ($json === null) {
            Log::error('Failed to extract JSON from Claude response', [
                'model'   => $model,
                'content' => mb_substr($content, 0, 500),
            ]);

            return null;
        }

        return $this->cleanUtf8($json);
    }

    // ─── DTO Mapping ───────────────────────────────────────────────

    /**
     * Map AI-normalised data to a ListingDTO with local imputation safety net.
     *
     * If the AI left `rooms` at 0 or `type` as unknown, a deterministic
     * regex-based analysis of the title/description fills the gap.
     * The title is validated against the structured format; if AI returned
     * a non-conforming title, the local normalizer rebuilds it.
     */
    private function mapToDTO(array $normalized, array $rawData): ListingDTO
    {
        $normalized   = $this->cleanUtf8($normalized);
        $rawDataArray = $rawData['raw_data'] ?? $rawData;

        if (isset($rawData['url']) && ! isset($rawDataArray['url'])) {
            $rawDataArray['url'] = $rawData['url'];
        }

        $images         = $this->assembleImageUrls($normalized, $rawData, $rawDataArray);
        $selectedImages = $this->extractAiCuration($normalized);

        if ($selectedImages !== null) {
            $rawDataArray['selected_images'] = $selectedImages;
        }
        $rawDataArray['extracted_images'] = $images;

        // Local imputation as a safety net (AI may have already done this)
        $desc  = $normalized['description'] ?? data_get($rawDataArray, 'description', '');
        $area  = isset($normalized['area_m2']) ? (float) $normalized['area_m2'] : 0.0;
        $rooms = isset($normalized['rooms']) ? (int) $normalized['rooms'] : 0;
        $type  = $normalized['type'] ?? 'unknown';
        $city   = $normalized['city'] ?? 'Unknown';
        $street = $normalized['street'] ?? data_get($rawDataArray, 'street');

        // Preserve original title for raw_data before any normalization
        $rawTitle = $normalized['raw_title']
            ?? data_get($rawDataArray, 'title')
            ?? data_get($rawData, 'title', '');

        if ($rawTitle !== '') {
            $rawDataArray['raw_title'] = $rawTitle;
        }

        if ($rooms <= 0) {
            $rooms = $this->imputeRoomsFromText($rawTitle, $desc, $area);
        }

        if ($type === 'unknown' || $type === null) {
            $type = $this->imputeTypeFromText($rawTitle, $desc);
        }

        // Title normalization: use AI title if it looks structured, otherwise build locally
        $aiTitle = $normalized['title'] ?? '';
        $title   = $this->isStructuredTitle($aiTitle)
            ? $aiTitle
            : $this->buildStructuredTitle($type, $rooms, $area, $street, $city);

        // Track imputed fields for observability
        $imputedFields = $normalized['imputed_fields'] ?? [];
        $rawDataArray['imputed_fields'] = is_array($imputedFields) ? $imputedFields : [];

        $dto = ListingDTO::fromArray([
            'external_id' => $rawData['external_id'] ?? null,
            'title'       => $title,
            'description' => $desc,
            'price'       => isset($normalized['price']) ? (float) $normalized['price'] : 0.0,
            'currency'    => $normalized['currency'] ?? 'PLN',
            'area_m2'     => $area,
            'rooms'       => $rooms,
            'city'        => $city,
            'street'      => $street,
            'type'        => $type,
            'status'      => ListingStatus::AVAILABLE->value,
            'raw_data'    => $rawDataArray,
            'images'      => $images,
            'keywords'    => is_array($normalized['keywords'] ?? null) ? $normalized['keywords'] : null,
        ]);

        Log::debug('AI normalization mapped to DTO', [
            'external_id'    => $dto->externalId,
            'quality_score'  => $dto->qualityScore(),
            'is_valid'       => $dto->isValid(),
            'imputed_fields' => $rawDataArray['imputed_fields'],
        ]);

        return $dto;
    }

    /**
     * Fallback DTO when AI fails but JSON-LD data was extracted.
     *
     * Uses raw JSON-LD values directly — no translation, no normalisation.
     * Applies local imputation for rooms/type, builds a structured title,
     * and runs validation to determine status.
     */
    private function mapJsonLdFallbackToDTO(array $jsonLd, array $rawData): ListingDTO
    {
        $rawDataArray = $rawData['raw_data'] ?? $rawData;

        if (isset($rawData['url']) && ! isset($rawDataArray['url'])) {
            $rawDataArray['url'] = $rawData['url'];
        }

        $rawDataArray['json_ld']          = $jsonLd;
        $rawDataArray['ai_fallback']      = true;
        $rawDataArray['extracted_images']  = $jsonLd['images'] ?? data_get($rawDataArray, 'extracted_images', []);

        $images = array_values(array_filter(
            $jsonLd['images'] ?? [],
            fn (string $url): bool => $this->isValidImageUrl($url),
        ));

        $rawTitle = $jsonLd['title'] ?? 'Property Listing';
        $desc     = $jsonLd['description'] ?? '';
        $area     = (float) ($jsonLd['area_m2'] ?? 0.0);
        $rooms    = (int) ($jsonLd['rooms'] ?? 0);
        $type     = $jsonLd['type'] ?? 'unknown';
        $city     = $jsonLd['city'] ?? 'Unknown';
        $street   = $jsonLd['street'] ?? null;

        // Preserve original title
        $rawDataArray['raw_title'] = $rawTitle;

        if ($rooms <= 0) {
            $rooms = $this->imputeRoomsFromText($rawTitle, $desc, $area);
        }

        if ($type === null || $type === 'unknown') {
            $type = $this->imputeTypeFromText($rawTitle, $desc);
        }

        // Build structured title locally (no AI available)
        $title = $this->buildStructuredTitle($type, $rooms, $area, $street, $city);

        $dto = ListingDTO::fromArray([
            'external_id' => $rawData['external_id'] ?? $jsonLd['external_id'] ?? null,
            'title'       => $title,
            'description' => $desc,
            'price'       => $jsonLd['price'] ?? 0.0,
            'currency'    => $jsonLd['currency'] ?? 'PLN',
            'area_m2'     => $area,
            'rooms'       => $rooms,
            'city'        => $city,
            'street'      => $street,
            'type'        => $type,
            'status'      => ListingStatus::UNVERIFIED->value,
            'raw_data'    => $rawDataArray,
            'images'      => $images,
            'keywords'    => null,
        ]);

        Log::debug('Fallback DTO created with imputation', [
            'external_id'   => $dto->externalId,
            'quality_score' => $dto->qualityScore(),
            'is_valid'      => $dto->isValid(),
            'status'        => $dto->resolveStatus()->value,
        ]);

        return $dto;
    }

    // ─── Local Imputation (Safety Net) ─────────────────────────────

    /**
     * Infer room count from title, description, or area when AI didn't resolve it.
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
     * Infer property type from title and description when AI returned unknown/null.
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

    // ─── Title Normalisation ────────────────────────────────────────

    /**
     * Build a structured title from individual fields.
     *
     * Format: "[N]-Bedroom [Type] in [City]" or "[N]-Bedroom [Type] on [Street] in [City]"
     * Omits segments when data is missing; never returns an empty string.
     */
    private function buildStructuredTitle(
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

        // Build property description: "3-Bedroom Apartment" or "Studio"
        if ($isStudio) {
            $parts[] = $typeLabel !== '' ? $typeLabel : 'Studio';
        } else {
            if ($rooms > 0) {
                $parts[] = $rooms . '-Bedroom';
            }
            if ($typeLabel !== '') {
                $parts[] = $typeLabel;
            }
        }

        $propertyDesc = implode(' ', $parts);

        // Build location: "on [Street] in [City]" or "in [City]"
        $locationParts = [];

        if ($street !== null && trim($street) !== '') {
            $locationParts[] = 'on ' . $this->cleanStreetForTitle($street);
        }

        if (trim($city) !== '' && $city !== 'Unknown') {
            $locationParts[] = 'in ' . trim($city);
        }

        $location = implode(' ', $locationParts);

        // Combine property and location
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
     * Looks for natural language patterns like "Bedroom", "in [City]", "on [Street]".
     * Also rejects titles that still contain marketing fluff.
     */
    private function isStructuredTitle(string $title): bool
    {
        if ($title === '') {
            return false;
        }

        // Must contain location indicator ("in" or "on")
        $hasLocation = preg_match('/\b(in|on)\s+[A-ZĄĆĘŁŃÓŚŹŻ][a-ząćęłńóśźż]+/u', $title);

        if (! $hasLocation) {
            return false;
        }

        // Reject if it still contains marketing fluff
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

    // ─── Image Assembly ────────────────────────────────────────────

    /**
     * Extract AI-curated image selection (hero + gallery) from normalised data.
     *
     * @return array{hero_url: string|null, gallery_urls: list<string>}|null
     */
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

    /**
     * Merge AI-returned and raw-extracted image URLs, filtering invalids.
     *
     * @return list<string>  Unique, validated image URLs.
     */
    private function assembleImageUrls(array $normalized, array $rawData, array $rawDataArray): array
    {
        $aiImages  = $normalized['images'] ?? [];
        $rawImages = $rawDataArray['images'] ?? $rawData['images'] ?? [];

        $candidates = array_values(array_unique(array_filter(array_merge(
            is_array($aiImages) ? $aiImages : [],
            is_array($rawImages) ? $rawImages : [],
        ))));

        return array_values(array_filter(
            $candidates,
            fn (mixed $url): bool => is_string($url) && $this->isValidImageUrl($url),
        ));
    }
}
