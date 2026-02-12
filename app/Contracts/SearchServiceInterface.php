<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\SearchCriteriaDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SearchServiceInterface
{
    /**
     * Execute a structured property search.
     */
    public function search(SearchCriteriaDTO $criteria): LengthAwarePaginator;
}
