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
 * Pipeline:
 *  1. Scrape raw HTML from provider (fast, no AI).
 *  2. Extract JSON-LD for semantic fingerprint (Phase 1).
 *  3. Check for recent duplicates (30-day window) — skip AI if found.
 *  4. Create skeleton Listing records immediately (status = PENDING).
 *  5. Dispatch parallel jobs:
 *     - ProcessAiNormalizationJob → `ai` queue (Claude normalisation).
 *     - DownloadListingImagesJob  → `media` queue (image downloads).
 *  6. Listings appear on site near-instantly as "pending".
 *
 * This command is a thin orchestrator — all domain logic lives in
 * ListingService, OtodomProvider, and the dispatched jobs.
 */
final class ImportListingsCommand extends Command
{
    protected $signature = 'listings:import
                            {--provider=otodom : The listing provider to use}
                            {--limit=10 : Maximum number of listings to import}
                            {--sync : Process synchronously (for testing, bypasses queue)}';

    protected $description = 'Import real estate listings — skeleton-first, then parallel AI + image processing';

    public function handle(
        ListingService $listingService,
        ProviderRegistryInterface $providerRegistry,
    ): int {
        ini_set('memory_limit', config('scraper.import.memory_limit', '256M'));

        $providerName = $this->option('provider');
        $limit        = (int) $this->option('limit');
        $sync         = (bool) $this->option('sync');
        $chunkSize    = (int) config('scraper.import.chunk_size', 5);

        $this->info("Starting import from {$providerName} provider (limit: {$limit})");
        $this->displayProcessingMode($sync);

        try {
            $provider = $providerRegistry->resolve($providerName);

            if ($provider === null) {
                $available = implode(', ', $providerRegistry->available());
                $this->error("Provider '{$providerName}' not found. Available: {$available}");

                return Command::FAILURE;
            }

            // Step 1: Scrape raw data (no AI — fast)
            $this->info('Scraping raw listings...');
            $rawListings = $provider->fetch($limit);

            if ($rawListings === []) {
                $this->warn('No listings fetched from provider');

                return Command::SUCCESS;
            }

            $this->info('Scraped <fg=green>' . count($rawListings) . '</> raw listings');

            // Step 2: Create skeletons and dispatch jobs (in chunks)
            $stats = $this->processListings($rawListings, $listingService, $chunkSize, $sync);

            // Step 3: Summary
            $this->displaySummary($stats, $sync);

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

    // ─── Processing ────────────────────────────────────────────────

    /**
     * Process raw listings in chunks: create skeletons and dispatch jobs.
     *
     * @return array{created: int, skipped_external: int, skipped_fingerprint: int, dispatched: int, errors: int}
     */
    private function processListings(
        array $rawListings,
        ListingService $listingService,
        int $chunkSize,
        bool $sync,
    ): array {
        $stats = [
            'created'              => 0,
            'skipped_external'     => 0,
            'skipped_fingerprint'  => 0,
            'dispatched'           => 0,
            'errors'               => 0,
        ];

        $chunks = array_chunk($rawListings, $chunkSize);
        $bar    = $this->output->createProgressBar(count($rawListings));
        $bar->start();

        foreach ($chunks as $chunk) {
            foreach ($chunk as $scraped) {
                try {
                    $result = $listingService->createSkeleton($scraped);

                    if (! $result['is_new']) {
                        $result['is_fingerprint_duplicate']
                            ? $stats['skipped_fingerprint']++
                            : $stats['skipped_external']++;
                        $bar->advance();
                        continue;
                    }

                    $listing = $result['listing'];
                    $stats['created']++;

                    $extractedImages = $scraped['extracted_images'] ?? [];

                    if ($sync) {
                        $this->processSync($listing->id, $extractedImages);
                    } else {
                        $this->dispatchParallel($listing->id, $extractedImages, $stats['dispatched']);
                        $stats['dispatched'] += 2; // AI + Images
                    }
                } catch (Throwable $e) {
                    $stats['errors']++;
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

        return $stats;
    }

    // ─── Job Dispatch ──────────────────────────────────────────────

    /**
     * Dispatch AI and image jobs in parallel with staggered AI delays.
     */
    private function dispatchParallel(int $listingId, array $extractedImages, int $jobCount): void
    {
        $aiDelay = (int) (($jobCount / 2) * 0.5);

        ProcessAiNormalizationJob::dispatch($listingId)
            ->delay(now()->addSeconds($aiDelay));

        DownloadListingImagesJob::dispatch($listingId, $extractedImages);
    }

    /**
     * Process AI normalisation and image download synchronously (testing mode).
     */
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

    // ─── Output ────────────────────────────────────────────────────

    private function displayProcessingMode(bool $sync): void
    {
        if ($sync) {
            $this->info('Processing mode: <fg=yellow>Sync (Testing)</>');
        } else {
            $this->info('Processing mode: <fg=cyan>Async (Queue)</>');
            $this->info('Queues: <fg=yellow>ai</> + <fg=yellow>media</> (parallel)');
            $this->warn('Start workers: <fg=yellow>php artisan queue:work --queue=ai,media</>');
        }
    }

    private function displaySummary(array $stats, bool $sync): void
    {
        $totalSkipped = $stats['skipped_external'] + $stats['skipped_fingerprint'];

        $this->info('=== Import Summary ===');
        $this->line("Skeletons created: <fg=green>{$stats['created']}</>");
        $this->line("Duplicates skipped: <fg=yellow>{$totalSkipped}</>");

        if ($stats['skipped_fingerprint'] > 0) {
            $this->line("  ├─ Fingerprint (pre-AI, saved \$): <fg=magenta>{$stats['skipped_fingerprint']}</>");
        }
        if ($stats['skipped_external'] > 0) {
            $this->line("  └─ External ID: <fg=yellow>{$stats['skipped_external']}</>");
        }

        if ($sync) {
            $this->line("Processed: <fg=green>{$stats['created']}</> (synchronously)");
        } else {
            $this->line("Jobs dispatched: <fg=cyan>{$stats['dispatched']}</> (AI + Images)");
        }

        if ($stats['errors'] > 0) {
            $this->line("Errors: <fg=red>{$stats['errors']}</>");
        }

        if ($stats['skipped_fingerprint'] > 0) {
            $this->newLine();
            $this->info("💰 Saved {$stats['skipped_fingerprint']} AI call(s) via fingerprint-first dedup.");
        }

        if (! $sync && $stats['created'] > 0) {
            $this->newLine();
            $this->info('Skeleton listings are now visible on the site.');
            $this->info('Start workers to process AI + images:');
            $this->line('  <fg=cyan>php artisan queue:work --queue=ai,media</>');
        }
    }
}
