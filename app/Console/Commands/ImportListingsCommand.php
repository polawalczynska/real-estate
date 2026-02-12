<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\AiNormalizerInterface;
use App\Contracts\ImageAttacherInterface;
use App\Contracts\ProviderRegistryInterface;
use App\Jobs\DownloadListingImagesJob;
use App\Jobs\ProcessAiNormalizationJob;
use App\Services\ListingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Import listings using the "Skeleton First" strategy with
 * fingerprint-first deduplication.
 *
 * Flow:
 *  1. Scrape raw HTML from provider (fast, no AI).
 *  2. Calculate semantic fingerprint from raw DOM metadata.
 *  3. Check for recent duplicates (30-day window) — skip AI if found.
 *  4. Create skeleton Listing records immediately (status = pending).
 *  5. Dispatch parallel jobs:
 *     - ProcessAiNormalizationJob → `ai` queue (Claude extraction).
 *     - DownloadListingImagesJob  → `media` queue (image downloads).
 *  6. Listings appear on site near-instantly as "pending".
 */
final class ImportListingsCommand extends Command
{
    private const CHUNK_SIZE = 5;

    protected $signature = 'listings:import
                            {--provider=otodom : The listing provider to use}
                            {--limit=10 : Maximum number of listings to import}
                            {--sync : Process synchronously (for testing, bypasses queue)}';

    protected $description = 'Import real estate listings — skeleton-first, then parallel AI + image processing';

    public function handle(
        ListingService $listingService,
        ProviderRegistryInterface $providerRegistry,
    ): int {
        ini_set('memory_limit', '256M');

        $providerName = $this->option('provider');
        $limit        = (int) $this->option('limit');
        $sync         = (bool) $this->option('sync');

        $this->info("Starting import from {$providerName} provider (limit: {$limit})");

        if (! $sync) {
            $this->info('Processing mode: <fg=cyan>Async (Queue)</>');
            $this->info('Queues: <fg=yellow>ai</> + <fg=yellow>media</> (parallel)');
            $this->warn('Start workers: <fg=yellow>php artisan queue:work --queue=ai,media</>');
        } else {
            $this->info('Processing mode: <fg=yellow>Sync (Testing)</>');
        }

        try {
            $provider = $providerRegistry->resolve($providerName);

            if ($provider === null) {
                $available = implode(', ', $providerRegistry->available());
                $this->error("Provider '{$providerName}' not found. Available: {$available}");

                return Command::FAILURE;
            }

            // Step 2: Scrape raw data (no AI — fast)
            $this->info('Scraping raw listings...');
            $rawListings = $provider->fetch($limit);

            if ($rawListings === []) {
                $this->warn('No listings fetched from provider');
                return Command::SUCCESS;
            }

            $this->info('Scraped <fg=green>' . count($rawListings) . '</> raw listings');

            // Step 3: Create skeletons and dispatch jobs (in chunks)
            $created               = 0;
            $skippedExternalId     = 0;
            $skippedFingerprint    = 0;
            $dispatched            = 0;
            $errors                = 0;

            $chunks = array_chunk($rawListings, self::CHUNK_SIZE);
            $bar    = $this->output->createProgressBar(count($rawListings));
            $bar->start();

            foreach ($chunks as $chunk) {
                foreach ($chunk as $scraped) {
                    try {
                        $persistResult = $listingService->createSkeleton($scraped);

                        if (! $persistResult['is_new']) {
                            if ($persistResult['is_fingerprint_duplicate']) {
                                $skippedFingerprint++;
                            } else {
                                $skippedExternalId++;
                            }
                            $bar->advance();
                            continue;
                        }

                        $listing = $persistResult['listing'];
                        $created++;

                        // Dispatch parallel jobs (only for genuinely new listings)
                        if ($sync) {
                            $this->processSync($listing->id, $scraped['extracted_images'] ?? []);
                        } else {
                            $this->dispatchParallel($listing->id, $scraped['extracted_images'] ?? [], $dispatched);
                            $dispatched += 2; // AI + Images
                        }

                    } catch (Throwable $e) {
                        $errors++;
                        Log::error('Failed to process listing', [
                            'external_id' => $scraped['external_id'] ?? null,
                            'error'       => $e->getMessage(),
                        ]);
                        $this->newLine();
                        $this->warn('Error: ' . $e->getMessage());
                    }

                    $bar->advance();
                }

                // Free memory between chunks
                unset($chunk);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            $bar->finish();
            $this->newLine(2);

            // Step 4: Summary
            $this->displaySummary($created, $skippedExternalId, $skippedFingerprint, $dispatched, $errors, $sync);

            return Command::SUCCESS;

        } catch (Throwable $e) {
            $this->error('Fatal error during import: ' . $e->getMessage());
            Log::error('Fatal import error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    private function dispatchParallel(int $listingId, array $extractedImages, int $jobCount): void
    {
        // Stagger AI jobs slightly to avoid overwhelming Claude
        $aiDelay    = (int) (($jobCount / 2) * 0.5);
        $mediaDelay = 0; // Images can start immediately

        ProcessAiNormalizationJob::dispatch($listingId)
            ->delay(now()->addSeconds($aiDelay));

        DownloadListingImagesJob::dispatch($listingId, $extractedImages)
            ->delay(now()->addSeconds($mediaDelay));
    }

    private function processSync(int $listingId, array $extractedImages): void
    {
        $aiJob = new ProcessAiNormalizationJob($listingId);
        $aiJob->handle(app(AiNormalizerInterface::class), app(ListingService::class));
        unset($aiJob);

        $imgJob = new DownloadListingImagesJob($listingId, $extractedImages);
        $imgJob->handle(app(ImageAttacherInterface::class));
        unset($imgJob);

        usleep(500_000);
    }

    private function displaySummary(
        int $created,
        int $skippedExternalId,
        int $skippedFingerprint,
        int $dispatched,
        int $errors,
        bool $sync,
    ): void {
        $totalSkipped = $skippedExternalId + $skippedFingerprint;

        $this->info('=== Import Summary ===');
        $this->line("Skeletons created: <fg=green>{$created}</>");
        $this->line("Duplicates skipped: <fg=yellow>{$totalSkipped}</>");

        if ($skippedFingerprint > 0) {
            $this->line("  ├─ Fingerprint (pre-AI, saved \$): <fg=magenta>{$skippedFingerprint}</>");
        }
        if ($skippedExternalId > 0) {
            $this->line("  └─ External ID: <fg=yellow>{$skippedExternalId}</>");
        }

        if ($sync) {
            $this->line("Processed: <fg=green>{$created}</> (synchronously)");
        } else {
            $this->line("Jobs dispatched: <fg=cyan>{$dispatched}</> (AI + Images)");
        }

        if ($errors > 0) {
            $this->line("Errors: <fg=red>{$errors}</>");
        }

        if ($skippedFingerprint > 0) {
            $this->newLine();
            $this->info("💰 Saved {$skippedFingerprint} AI call(s) via fingerprint-first dedup.");
        }

        if (! $sync && $created > 0) {
            $this->newLine();
            $this->info('Skeleton listings are now visible on the site.');
            $this->info('Start workers to process AI + images:');
            $this->line('  <fg=cyan>php artisan queue:work --queue=ai,media</>');
        }
    }
}
