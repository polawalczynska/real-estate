<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PropertyType;
use App\Models\Listing;
use Illuminate\Console\Command;

/**
 * Retroactively rebuild listing titles to follow the human-friendly format:
 * "[N]-Bedroom [Type] in [City]" or "[N]-Bedroom [Type] on [Street] in [City]"
 *
 * Useful after deploying the title normalization logic to fix
 * listings that were processed before the change.
 */
final class NormalizeTitlesCommand extends Command
{
    protected $signature = 'listings:normalize-titles
                            {--dry-run : Preview changes without saving}';

    protected $description = 'Rebuild listing titles using the human-friendly format: [N]-Bedroom [Type] in [City]';

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $listings = Listing::all(['id', 'title', 'type', 'rooms', 'area_m2', 'city', 'street', 'raw_data']);

        if ($listings->isEmpty()) {
            $this->info('No listings found.');

            return Command::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Processing {$listings->count()} listing(s)...");
        $this->newLine();

        $updated = 0;
        $skipped = 0;

        foreach ($listings as $listing) {
            $newTitle = $this->buildStructuredTitle($listing);

            if ($newTitle === $listing->title) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  <fg=yellow>#{$listing->id}</>: {$listing->title}");
                $this->line("        → <fg=green>{$newTitle}</>");
                $this->newLine();
            } else {
                // Preserve original title in raw_data
                $rawData = is_array($listing->raw_data) ? $listing->raw_data : [];
                if (! isset($rawData['raw_title'])) {
                    $rawData['raw_title'] = $listing->title;
                }

                $listing->update([
                    'title'    => $newTitle,
                    'raw_data' => $rawData,
                ]);
            }

            $updated++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Updated: {$updated}, Skipped (already correct): {$skipped}");

        if ($dryRun && $updated > 0) {
            $this->newLine();
            $this->info('Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }

    private function buildStructuredTitle(Listing $listing): string
    {
        $typeEnum  = $listing->type instanceof PropertyType ? $listing->type : PropertyType::fromSafe((string) $listing->type);
        $typeLabel = $typeEnum !== PropertyType::UNKNOWN ? $typeEnum->label() : '';
        $isStudio  = $typeEnum === PropertyType::STUDIO;
        $rooms     = (int) $listing->rooms;
        $city      = (string) $listing->city;
        $street    = (string) ($listing->street ?? '');

        $parts = [];

        // Build property description: "3-Bedroom Apartment" or "Studio"
        if ($isStudio) {
            $parts[] = $typeLabel !== '' ? $typeLabel : 'Studio';
        } else {
            if ($rooms > 0) {
                $parts[] = $rooms . '-Bedroom';
            }
            if ($typeLabel !== '') {
                $parts[] = $typeLabel;
            }
        }

        $propertyDesc = implode(' ', $parts);

        // Build location: "on [Street] in [City]" or "in [City]"
        $locationParts = [];

        if ($street !== '') {
            $cleanStreet = trim(preg_replace('/^(?:ulica|ul\.?)\s*/iu', '', trim($street)));
            if ($cleanStreet !== '') {
                $locationParts[] = 'on ' . $cleanStreet;
            }
        }

        if (trim($city) !== '' && $city !== 'Unknown') {
            $locationParts[] = 'in ' . trim($city);
        }

        $location = implode(' ', $locationParts);

        // Combine property and location
        if ($propertyDesc !== '' && $location !== '') {
            return $propertyDesc . ' ' . $location;
        }

        if ($propertyDesc !== '') {
            return $propertyDesc;
        }

        if ($location !== '') {
            return 'Property ' . $location;
        }

        return 'Property Listing';
    }
}
