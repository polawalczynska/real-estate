<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiSearchInterface;
use App\DTOs\SearchCriteriaDTO;
use App\Services\Ai\Concerns\InteractsWithClaude;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI Concierge — translates natural-language property queries
 * into structured SearchCriteriaDTO using Claude.
 *
 * Flow: "Sunny loft in Krakow under 2M" → Claude → SearchCriteriaDTO
 */
final class AiSearchService implements AiSearchInterface
{
    use InteractsWithClaude;

    private const TIMEOUT_SECONDS      = 15;
    private const CACHE_TTL_HOURS      = 24;
    private const MAX_CONVERSE_TOKENS  = 1024;
    private const MAX_INTENT_TOKENS    = 512;

    public function __construct(
        private readonly JsonRepairService $jsonRepair,
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {}

    public function parseIntent(string $query): SearchCriteriaDTO
    {
        $query = trim($query);

        if ($query === '') {
            return SearchCriteriaDTO::empty();
        }

        $cacheKey = 'ai_search:' . md5(mb_strtolower($query));

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($query): SearchCriteriaDTO {
            return $this->callClaude($query);
        });
    }

    public function converse(string $userMessage, array $history = []): array
    {
        $userMessage = trim($userMessage);

        if ($userMessage === '') {
            return [
                'message'  => 'Please describe the space you envision — the light, the neighbourhood, the feeling.',
                'criteria' => SearchCriteriaDTO::empty(),
            ];
        }

        try {
            $apiKey = $this->resolveApiKey();

            if (empty($apiKey)) {
                return $this->fallbackConverse($userMessage);
            }

            $messages = [];
            foreach ($history as $entry) {
                $messages[] = ['role' => $entry['role'], 'content' => $entry['content']];
            }
            $messages[] = ['role' => 'user', 'content' => $userMessage];

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders($this->claudeHeaders($apiKey))
                ->post(self::CLAUDE_API_URL, [
                    'model'      => $this->resolveModel(configKey: 'search_model', default: 'claude-sonnet-4-5-20250929'),
                    'max_tokens' => self::MAX_CONVERSE_TOKENS,
                    'system'     => config('ai_prompts.search.converse_system'),
                    'messages'   => $messages,
                ]);

            if (! $response->successful()) {
                Log::warning('AiSearchService: Converse API failed', ['status' => $response->status()]);
                return $this->fallbackConverse($userMessage);
            }

            return $this->parseConverseResponse($response->json('content.0.text', ''), $userMessage);
        } catch (Throwable $e) {
            Log::error('AiSearchService: Converse exception', ['error' => $e->getMessage()]);
            return $this->fallbackConverse($userMessage);
        }
    }

    private function callClaude(string $query): SearchCriteriaDTO
    {
        try {
            $apiKey = $this->resolveApiKey();

            if (empty($apiKey)) {
                return $this->fallbackParse($query);
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders($this->claudeHeaders($apiKey))
                ->post(self::CLAUDE_API_URL, [
                    'model'      => $this->resolveModel(configKey: 'search_model', default: 'claude-sonnet-4-5-20250929'),
                    'max_tokens' => self::MAX_INTENT_TOKENS,
                    'system'     => config('ai_prompts.search.intent_system'),
                    'messages'   => [['role' => 'user', 'content' => $query]],
                ]);

            if (! $response->successful()) {
                Log::warning('AiSearchService: Intent parse failed', ['status' => $response->status()]);
                return $this->fallbackParse($query);
            }

            $content = $response->json('content.0.text', '');
            $json    = $this->jsonRepair->extract($content);

            if ($json === null) {
                return $this->fallbackParse($query);
            }

            return SearchCriteriaDTO::fromArray($json);
        } catch (Throwable $e) {
            Log::error('AiSearchService: Exception', ['query' => $query, 'error' => $e->getMessage()]);
            return $this->fallbackParse($query);
        }
    }

    private function parseConverseResponse(string $content, string $originalQuery): array
    {
        $prose = $content;
        $json  = null;

        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json  = json_decode($matches[1], true);
            $prose = trim(preg_replace('/```json\s*\{.*?\}\s*```/s', '', $content));
        }

        if ($json === null) {
            $lastBrace = strrpos($content, '}');
            if ($lastBrace !== false) {
                $firstBrace = strrpos(substr($content, 0, $lastBrace), '{');
                if ($firstBrace !== false) {
                    $candidate = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
                    $decoded   = json_decode($candidate, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $json  = $decoded;
                        $prose = trim(substr($content, 0, $firstBrace));
                    }
                }
            }
        }

        $criteria = ($json !== null && $json !== [])
            ? SearchCriteriaDTO::fromArray($json)
            : $this->fallbackParse($originalQuery);

        $prose = trim($prose);
        if ($prose === '') {
            $prose = 'Let me search for spaces that match your vision.';
        }

        return ['message' => $prose, 'criteria' => $criteria];
    }

    private function fallbackParse(string $query): SearchCriteriaDTO
    {
        $lower = mb_strtolower($query);

        $city   = $this->detectCity($lower);
        $type   = $this->detectType($lower);
        $priceMax = $this->detectPriceMax($lower);
        $keywords = $this->detectKeywords($lower);

        return SearchCriteriaDTO::fromArray([
            'city'      => $city,
            'type'      => $type,
            'price_max' => $priceMax,
            'keywords'  => $keywords ?: null,
            'search'    => $query,
        ]);
    }

    private function detectCity(string $lower): ?string
    {
        $cities = [
            'kraków' => 'Krakow', 'krakow' => 'Krakow', 'cracow' => 'Krakow',
            'warszawa' => 'Warsaw', 'warsaw' => 'Warsaw',
            'gdańsk' => 'Gdansk', 'gdansk' => 'Gdansk',
            'wrocław' => 'Wroclaw', 'wroclaw' => 'Wroclaw',
            'poznań' => 'Poznan', 'poznan' => 'Poznan',
            'łódź' => 'Lodz', 'lodz' => 'Lodz',
            'katowice' => 'Katowice',
            'szczecin' => 'Szczecin',
            'lublin' => 'Lublin',
            'bydgoszcz' => 'Bydgoszcz',
            'białystok' => 'Bialystok', 'bialystok' => 'Bialystok',
            'rzeszów' => 'Rzeszow', 'rzeszow' => 'Rzeszow',
        ];

        foreach ($cities as $needle => $canonical) {
            if (str_contains($lower, $needle)) {
                return $canonical;
            }
        }

        return null;
    }

    private function detectType(string $lower): ?string
    {
        foreach (['loft', 'penthouse', 'studio', 'villa', 'townhouse', 'house', 'apartment'] as $type) {
            if (str_contains($lower, $type)) {
                return $type;
            }
        }

        return null;
    }

    private function detectPriceMax(string $lower): ?float
    {
        if (preg_match('/(?:under|below|max|up\s+to|do)\s*([\d.,]+)\s*(m|mln|million|k|tys)?/i', $lower, $m)) {
            $amount = (float) str_replace([',', ' '], ['', ''], $m[1]);
            $suffix = strtolower($m[2] ?? '');
            if (in_array($suffix, ['m', 'mln', 'million'], true)) {
                $amount *= 1_000_000;
            } elseif (in_array($suffix, ['k', 'tys'], true)) {
                $amount *= 1_000;
            }
            return $amount;
        }

        return null;
    }

    private function detectKeywords(string $lower): array
    {
        $terms = [
            'sunny', 'bright', 'quiet', 'garden', 'terrace', 'balcony',
            'modern', 'minimalist', 'spacious', 'high ceilings', 'courtyard', 'evening light',
        ];

        $found = [];
        foreach ($terms as $kw) {
            if (str_contains($lower, $kw)) {
                $found[] = $kw;
            }
        }

        return $found;
    }

    private function fallbackConverse(string $userMessage): array
    {
        $criteria = $this->fallbackParse($userMessage);

        $parts = [];
        if ($criteria->city !== null) {
            $parts[] = "in {$criteria->city}";
        }
        if ($criteria->type !== null) {
            $parts[] = "of type {$criteria->type}";
        }

        $locationClue = $parts !== [] ? ' ' . implode(' ', $parts) : '';

        return [
            'message'  => "Let me search for spaces{$locationClue} that match your vision.",
            'criteria' => $criteria,
        ];
    }
}
