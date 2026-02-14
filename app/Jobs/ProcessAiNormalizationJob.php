<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\ListingDTO;
use App\Enums\ListingStatus;
use App\Exceptions\AiNormalizationException;
use App\Models\Listing;
use App\Services\Ai\AiNormalizationService;
use App\Services\ListingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI normalization job — runs on the `ai` queue.
 *
 * Thin orchestrator: reads pre-extracted JSON-LD from the skeleton
 * listing, sends it to the AI service for normalisation, then
 * delegates all persistence logic to ListingService.
 */
final class ProcessAiNormalizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;

    private const RATE_LIMIT_DELAYS  = [60, 120, 240, 480, 960];

    public function backoff(): array
    {
        return [120, 300, 600, 900, 1200];
    }

    public function __construct(
        private readonly int $listingId,
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        AiNormalizationService $aiService,
        ListingService $listingService,
    ): void {
        ini_set('memory_limit', '256M');

        $listing = Listing::find($this->listingId);

        if ($listing === null) {
            Log::warning('AI job: Listing not found', ['listing_id' => $this->listingId]);

            return;
        }

        if ($listing->status !== ListingStatus::PENDING) {
            return;
        }

        try {
            // ── Pipeline Step 1-3: Extract → AI Imputation → DTO ──
            $dto = $this->normalizeWithRetry($aiService, $listing);

            if ($dto === null) {
                return;
            }

            // ── Pipeline Step 4-6: Validate → Score → Save ────────
            $result = $listingService->applyNormalization($listing, $dto);

            Log::info('AI pipeline complete', [
                'listing_id'    => $this->listingId,
                'merged'        => $result['merged'],
                'quality_score' => $result['quality_score'] ?? null,
                'status'        => $result['status'] ?? null,
            ]);

            unset($dto);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        } catch (Throwable $e) {
            Log::error('AI normalization job failed', [
                'listing_id' => $this->listingId,
                'error'      => $e->getMessage(),
                'attempt'    => $this->attempts(),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('AI normalization permanently failed', [
            'listing_id'      => $this->listingId,
            'error'           => $exception?->getMessage(),
            'attempts'        => $this->attempts(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);

        try {
            app(ListingService::class)->markUnverified($this->listingId);
        } catch (Throwable $e) {
            Log::error('Fallback status update also failed', [
                'listing_id' => $this->listingId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function normalizeWithRetry(AiNormalizationService $aiService, Listing $listing): ?ListingDTO
    {
        $rawData = [
            'external_id' => $listing->external_id,
            'raw_html'    => $listing->raw_data['html'] ?? '',
            'raw_data'    => $listing->raw_data ?? [],
            'json_ld'     => $listing->raw_data['json_ld'] ?? null,
        ];

        try {
            return $aiService->normalize($rawData);
        } catch (AiNormalizationException $e) {
            if (! $e->retryable) {
                throw $e;
            }

            if ($this->attempts() >= $this->tries) {
                Log::warning("All retries exhausted ({$e->httpStatus}), marking as unverified.", [
                    'listing_id' => $this->listingId,
                    'attempts'   => $this->attempts(),
                ]);

                throw $e;
            }

            $delays = $e->httpStatus === 429 ? self::RATE_LIMIT_DELAYS : $this->backoff();
            $index  = min($this->attempts() - 1, count($delays) - 1);

            Log::warning('Releasing AI job for retry', [
                'listing_id' => $this->listingId,
                'reason'     => $e->httpStatus,
                'attempt'    => $this->attempts(),
                'delay_sec'  => $delays[$index],
            ]);

            $this->release(now()->addSeconds($delays[$index]));

            return null;
        }
    }
}
