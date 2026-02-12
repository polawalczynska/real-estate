<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class AiNormalizationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function rateLimited(string $detail = ''): self
    {
        return new self(
            message: "Claude API rate limit exceeded. {$detail}",
            retryable: true,
            httpStatus: 429,
        );
    }

    public static function overloaded(string $detail = ''): self
    {
        return new self(
            message: "Claude API overloaded. {$detail}",
            retryable: true,
            httpStatus: 529,
        );
    }

    public static function apiError(int $status, string $detail = ''): self
    {
        return new self(
            message: "Claude API returned {$status}: {$detail}",
            retryable: false,
            httpStatus: $status,
        );
    }

    public static function jsonParseFailed(string $detail = ''): self
    {
        return new self(
            message: "Failed to parse JSON from AI response. {$detail}",
            retryable: false,
        );
    }

    public static function missingApiKey(): self
    {
        return new self(
            message: 'Anthropic API key not configured.',
            retryable: false,
        );
    }
}
