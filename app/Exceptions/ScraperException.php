<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ScraperException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $url = null,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function networkError(string $url, string $detail = ''): self
    {
        return new self(
            message: "Network error fetching {$url}: {$detail}",
            url: $url,
        );
    }

    public static function httpError(string $url, int $status): self
    {
        return new self(
            message: "HTTP {$status} fetching {$url}",
            url: $url,
            httpStatus: $status,
        );
    }

    public static function parseFailed(string $url, string $detail = ''): self
    {
        return new self(
            message: "Failed to parse offer at {$url}: {$detail}",
            url: $url,
        );
    }
}
