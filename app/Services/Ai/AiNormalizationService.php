<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\DTOs\ListingDTO;
use App\Enums\ListingStatus;
use App\Exceptions\AiNormalizationException;
use App\Services\Ai\Concerns\AssemblesImages;
use App\Services\Ai\Concerns\ImputesListingData;
use App\Services\Ai\Concerns\InteractsWithClaude;
use App\Services\Ai\Concerns\NormalizesTitle;
use App\Services\Concerns\HandlesDataCleaning;
use App\Services\Concerns\ValidatesImageUrls;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives pre-extracted JSON-LD data from Phase 1 and sends it to Claude
 * for consistency normalisation (translation, description cleaning, type
 * mapping, image curation). Falls back to raw JSON-LD with local imputation
 * when the AI call fails.
 */
final class AiNormalizationService
{
    use InteractsWithClaude;
    use HandlesDataCleaning;
    use ValidatesImageUrls;
    use ImputesListingData;
    use NormalizesTitle;
    use AssemblesImages;

    public function __construct(
        private readonly JsonRepairService $jsonRepair,
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {}

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
                Log::error('Claude API exception', ['error' => $e->getMessage(), 'model' => $model]);

                if ($attemptIndex === 0 && count($modelsToTry) > 1) {
                    continue;
                }

                return null;
            }
        }

        return null;
    }


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

        return str_replace(
            ['{jsonData}', '{imageSection}'],
            [$jsonData, $this->buildImageSection($images)],
            config('ai_prompts.normalization.user'),
        );
    }
    private function handleApiError(Response $response, string $model, int $attemptIndex, array $modelsToTry): null
    {
        $status       = $response->status();
        $errorMessage = data_get($response->json(), 'error.message', $response->body());

        Log::error('Claude API request failed', ['status' => $status, 'model' => $model, 'error' => $errorMessage]);

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

        $desc   = $normalized['description'] ?? data_get($rawDataArray, 'description', '');
        $area   = isset($normalized['area_m2']) ? (float) $normalized['area_m2'] : 0.0;
        $rooms  = isset($normalized['rooms']) ? (int) $normalized['rooms'] : 0;
        $type   = $normalized['type'] ?? 'unknown';
        $city   = $normalized['city'] ?? 'Unknown';
        $street = $normalized['street'] ?? data_get($rawDataArray, 'street');

        $rawTitle = $normalized['raw_title'] ?? data_get($rawDataArray, 'title') ?? data_get($rawData, 'title', '');
        if ($rawTitle !== '') {
            $rawDataArray['raw_title'] = $rawTitle;
        }

        if ($rooms <= 0) {
            $rooms = $this->imputeRoomsFromText($rawTitle, $desc, $area);
        }
        if ($type === 'unknown' || $type === null) {
            $type = $this->imputeTypeFromText($rawTitle, $desc);
        }

        $aiTitle = $normalized['title'] ?? '';
        $title   = $this->isStructuredTitle($aiTitle)
            ? $aiTitle
            : $this->buildStructuredTitle($type, $rooms, $area, $street, $city);

        $imputedFields = $normalized['imputed_fields'] ?? [];
        $rawDataArray['imputed_fields'] = is_array($imputedFields) ? $imputedFields : [];

        return ListingDTO::fromArray([
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
    }

    private function mapJsonLdFallbackToDTO(array $jsonLd, array $rawData): ListingDTO
    {
        $rawDataArray = $rawData['raw_data'] ?? $rawData;

        if (isset($rawData['url']) && ! isset($rawDataArray['url'])) {
            $rawDataArray['url'] = $rawData['url'];
        }

        $rawDataArray['json_ld']     = $jsonLd;
        $rawDataArray['ai_fallback'] = true;

        $extractedImages = data_get($rawDataArray, 'extracted_images', []);
        $rawDataArray['extracted_images'] = $jsonLd['images'] ?? $extractedImages;

        $imageCandidates = array_merge($jsonLd['images'] ?? [], is_array($extractedImages) ? $extractedImages : []);
        $images = [];
        foreach ($imageCandidates as $img) {
            $url = $this->extractUrlFromImage($img);
            if ($url !== null && $this->isValidImageUrl($url)) {
                $images[] = $url;
            }
        }
        $images = array_values(array_unique($images));

        $rawTitle = $jsonLd['title'] ?? 'Property Listing';
        $desc     = $jsonLd['description'] ?? '';
        $area     = (float) ($jsonLd['area_m2'] ?? 0.0);
        $rooms    = (int) ($jsonLd['rooms'] ?? 0);
        $type     = $jsonLd['type'] ?? 'unknown';
        $city     = $jsonLd['city'] ?? 'Unknown';
        $street   = $jsonLd['street'] ?? null;

        $rawDataArray['raw_title'] = $rawTitle;

        if ($rooms <= 0) {
            $rooms = $this->imputeRoomsFromText($rawTitle, $desc, $area);
        }
        if ($type === null || $type === 'unknown') {
            $type = $this->imputeTypeFromText($rawTitle, $desc);
        }

        return ListingDTO::fromArray([
            'external_id' => $rawData['external_id'] ?? $jsonLd['external_id'] ?? null,
            'title'       => $this->buildStructuredTitle($type, $rooms, $area, $street, $city),
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
    }
}
