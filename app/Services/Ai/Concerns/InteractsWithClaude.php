<?php

declare(strict_types=1);

namespace App\Services\Ai\Concerns;

/**
 * Shared Claude API plumbing for AI services.
 *
 * Resolves API key, model, and headers from config/services.php,
 * ensuring no credentials are hardcoded in service classes.
 */
trait InteractsWithClaude
{
    private function resolveApiKey(): ?string
    {
        return $this->apiKey ?? config('services.anthropic.api_key');
    }

    private function resolveModel(
        string $configKey = 'model',
        string $default = 'claude-haiku-4-5-20251001',
    ): string {
        return $this->model
            ?? config("services.anthropic.{$configKey}")
            ?? $default;
    }

    private function claudeApiUrl(): string
    {
        return config('services.anthropic.api_url', 'https://api.anthropic.com/v1/messages');
    }

    private function claudeHeaders(string $apiKey): array
    {
        return [
            'x-api-key'         => $apiKey,
            'anthropic-version'  => config('services.anthropic.api_version', '2023-06-01'),
            'content-type'       => 'application/json',
        ];
    }
}
