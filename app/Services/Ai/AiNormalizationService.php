<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiNormalizerInterface;
use App\DTOs\ListingDTO;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Exceptions\AiNormalizationException;
use App\Services\Ai\Concerns\InteractsWithClaude;
use App\Services\Concerns\ValidatesImageUrls;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates AI-driven listing normalisation via Claude.
 *
 * Heavy lifting is delegated to:
 *  - HtmlExtractorService  → DOM plucking, image extraction
 *  - JsonRepairService     → JSON extraction / repair / UTF-8 cleaning
 */
final class AiNormalizationService implements AiNormalizerInterface
{
    use InteractsWithClaude;
    use ValidatesImageUrls;

    private const TIMEOUT_SECONDS  = 30;
    private const MAX_TOKENS       = 4096;
    private const FALLBACK_MODEL   = 'claude-sonnet-4-5-20250929';

    private array $lastExtractedImages = [];

    public function __construct(
        private readonly HtmlExtractorService $htmlExtractor,
        private readonly JsonRepairService $jsonRepair,
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {}

    public function normalize(array $rawData): ListingDTO
    {
        $normalized = $this->callClaudeApi($rawData);

        if ($normalized === null) {
            throw AiNormalizationException::apiError(0, 'Claude returned no usable data.');
        }

        return $this->mapToDTO($normalized, $rawData);
    }

    private function callClaudeApi(array $rawData): ?array
    {
        $apiKey = $this->resolveApiKey();

        if ($apiKey === null) {
            throw AiNormalizationException::missingApiKey();
        }

        $prompt        = $this->buildPrompt($rawData);
        $primaryModel  = $this->resolveModel(configKey: 'model', default: 'claude-haiku-4-5-20251001');
        $modelsToTry   = array_values(array_unique([$primaryModel, self::FALLBACK_MODEL]));

        foreach ($modelsToTry as $attemptIndex => $model) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders($this->claudeHeaders($apiKey))
                    ->post(self::CLAUDE_API_URL, [
                        'model'      => $model,
                        'max_tokens' => self::MAX_TOKENS,
                        'system'     => config('ai_prompts.normalization.system'),
                        'messages'   => [['role' => 'user', 'content' => $prompt]],
                    ]);

                if (! $response->successful()) {
                    return $this->handleApiError($response, $model, $attemptIndex, $modelsToTry);
                }

                return $this->parseApiResponse($response, $model, $rawData);
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

    private function buildPrompt(array $rawData): string
    {
        $html = $rawData['raw_html'] ?? '';

        if ($html !== '') {
            $condensedHtml             = $this->htmlExtractor->extractContent($html);
            $this->lastExtractedImages = $this->htmlExtractor->getLastExtractedImages();

            return str_replace(
                ['{html}', '{imageSection}'],
                [$condensedHtml, $this->buildImageSection()],
                config('ai_prompts.normalization.user_html'),
            );
        }

        $jsonData = json_encode(
            $rawData['raw_data'] ?? $rawData,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        );

        return str_replace('{jsonData}', $jsonData, config('ai_prompts.normalization.user_structured'));
    }

    private function buildImageSection(): string
    {
        if (empty($this->lastExtractedImages)) {
            return '';
        }

        $lines = [];
        foreach ($this->lastExtractedImages as $i => $img) {
            $label   = $img['label'] ? " (Label: {$img['label']})" : '';
            $lines[] = ($i + 1) . '. ' . $img['url'] . $label;
        }

        return "\n\n=== PROPERTY IMAGES ===\n" . implode("\n", $lines) . "\n=== END IMAGES ===";
    }

    private function handleApiError(
        \Illuminate\Http\Client\Response $response,
        string $model,
        int $attemptIndex,
        array $modelsToTry,
    ): null {
        $status       = $response->status();
        $errorBody    = $response->json();
        $errorMessage = $errorBody['error']['message'] ?? $response->body();

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

    private function parseApiResponse(
        \Illuminate\Http\Client\Response $response,
        string $model,
        array $rawData,
    ): ?array {
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

        return $this->jsonRepair->cleanUtf8($json);
    }

    private function mapToDTO(array $normalized, array $rawData): ListingDTO
    {
        $normalized   = $this->jsonRepair->cleanUtf8($normalized);
        $rawDataArray = $rawData['raw_data'] ?? $rawData;
        $html         = $rawData['raw_html'] ?? '';

        if ($html !== '' && ! isset($rawDataArray['html'])) {
            $rawDataArray['html'] = $html;
        }
        if (isset($rawData['url']) && ! isset($rawDataArray['url'])) {
            $rawDataArray['url'] = $rawData['url'];
        }

        $images         = $this->assembleImageUrls($normalized, $rawData, $rawDataArray);
        $selectedImages = $this->extractAiCuration($normalized);

        if ($selectedImages !== null) {
            $rawDataArray['selected_images'] = $selectedImages;
        }
        $rawDataArray['extracted_images'] = $images;

        return ListingDTO::fromArray([
            'external_id' => $rawData['external_id'] ?? null,
            'title'       => $normalized['title'] ?? 'Property Listing',
            'description' => $normalized['description'] ?? $rawDataArray['description'] ?? '',
            'price'       => isset($normalized['price']) ? (float) $normalized['price'] : 0.0,
            'currency'    => $normalized['currency'] ?? 'PLN',
            'area_m2'     => isset($normalized['area_m2']) ? (float) $normalized['area_m2'] : 0.0,
            'rooms'       => isset($normalized['rooms']) ? (int) $normalized['rooms'] : 1,
            'city'        => $normalized['city'] ?? 'Unknown',
            'street'      => $normalized['street'] ?? $rawDataArray['street'] ?? null,
            'type'        => $normalized['type'] ?? PropertyType::APARTMENT->value,
            'status'      => ListingStatus::AVAILABLE->value,
            'raw_data'    => $rawDataArray,
            'images'      => $images,
            'keywords'    => is_array($normalized['keywords'] ?? null) ? $normalized['keywords'] : null,
        ]);
    }

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
        $aiImages      = $normalized['images'] ?? [];
        $rawImages     = $rawDataArray['images'] ?? $rawData['images'] ?? [];
        $extractedUrls = array_map(fn (array $img): string => $img['url'], $this->lastExtractedImages);

        $candidates = array_values(array_unique(array_filter(array_merge(
            is_array($aiImages) ? $aiImages : [],
            is_array($rawImages) ? $rawImages : [],
            $extractedUrls,
        ))));

        return array_values(array_filter(
            $candidates,
            fn (mixed $url): bool => is_string($url) && $this->isValidImageUrl($url),
        ));
    }
}
