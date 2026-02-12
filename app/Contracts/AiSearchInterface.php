<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\SearchCriteriaDTO;

interface AiSearchInterface
{
    /**
     * Parse a natural-language search query into structured criteria.
     */
    public function parseIntent(string $query): SearchCriteriaDTO;

    /**
     * Generate a conversational AI response with extracted criteria.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{message: string, criteria: SearchCriteriaDTO}
     */
    public function converse(string $userMessage, array $history = []): array;
}
