<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ImageDownloadException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $url = null,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidUrl(string $url): self
    {
        return new self(
            message: "Invalid image URL: {$url}",
            url: $url,
        );
    }

    public static function httpError(string $url, int $status): self
    {
        return new self(
            message: "HTTP {$status} downloading image from {$url}",
            url: $url,
            httpStatus: $status,
        );
    }

    public static function invalidMime(string $url, string $mime): self
    {
        return new self(
            message: "Invalid MIME type '{$mime}' for image at {$url}",
            url: $url,
        );
    }
}
