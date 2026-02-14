<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\SearchCriteriaDTO;
use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class SearchService
{
    private const VALID_SORT_FIELDS = ['price', 'created_at', 'area_m2'];

    public function __construct(
        private readonly Listing $listing,
    ) {
    }

    public function search(SearchCriteriaDTO $criteria): LengthAwarePaginator
    {
        $errors = $criteria->validate();

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $query = $this->listing->newQuery();

        $query->filtered($criteria->toFilterArray());

        $this->applySorting($query, $criteria->sortBy, $criteria->sortOrder);

        $query->select(Listing::CARD_COLUMNS);

        return $query->paginate($criteria->perPage);
    }

    private function applySorting(Builder $query, string $sortBy, string $sortOrder): void
    {
        $sortBy = in_array($sortBy, self::VALID_SORT_FIELDS, true) ? $sortBy : 'created_at';
        $sortOrder = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);
    }
}
