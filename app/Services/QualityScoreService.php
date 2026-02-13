<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ListingDTO;
use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates data completeness and determines final listing status.
 *
 * Orchestrates DTO validation, quality scoring, and status resolution
 * into a single pipeline step called after AI normalization.
 *
 * Scoring:
 *   Base = 100, deductions per missing optional field:
 *     -20 street
 *     -10 rooms, description, type
 *      -5 keywords, images
 *
 * Status resolution:
 *   All critical fields present → AVAILABLE
 *   Some critical missing       → INCOMPLETE (hidden)
 *   All critical missing        → FAILED (hidden)
 */
final class QualityScoreService
{
    /**
     * Evaluate a DTO and return scoring + status metadata.
     *
     * @return array{
     *     quality_score: int,
     *     is_fully_parsed: bool,
     *     status: ListingStatus,
     *     validation_errors: array<string, string>,
     * }
     */
    public function evaluate(ListingDTO $dto): array
    {
        $validationErrors = $dto->validate();
        $qualityScore     = $dto->qualityScore();
        $isFullyParsed    = $dto->isFullyParsed();
        $status           = $dto->resolveStatus();

        return [
            'quality_score'     => $qualityScore,
            'is_fully_parsed'   => $isFullyParsed,
            'status'            => $status,
            'validation_errors' => $validationErrors,
        ];
    }

    /**
     * Apply quality evaluation to an existing Listing model.
     *
     * Updates quality_score, is_fully_parsed, and adjusts status
     * if critical fields are missing.
     */
    public function applyToListing(Listing $listing, ListingDTO $dto): ListingStatus
    {
        $evaluation = $this->evaluate($dto);

        $listing->update([
            'quality_score'   => $evaluation['quality_score'],
            'is_fully_parsed' => $evaluation['is_fully_parsed'],
        ]);

        if ($evaluation['validation_errors'] !== []) {
            Log::warning('Listing failed critical-field validation', [
                'listing_id' => $listing->id,
                'errors'     => $evaluation['validation_errors'],
                'score'      => $evaluation['quality_score'],
                'status'     => $evaluation['status']->value,
            ]);
        } else {
            Log::debug('Listing quality evaluated', [
                'listing_id'      => $listing->id,
                'quality_score'   => $evaluation['quality_score'],
                'is_fully_parsed' => $evaluation['is_fully_parsed'],
            ]);
        }

        return $evaluation['status'];
    }
}
