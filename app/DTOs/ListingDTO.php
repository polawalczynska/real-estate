<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Services\FingerprintService;
use Illuminate\Support\Str;

/**
 * Immutable DTO that carries listing data through the entire pipeline.
 *
 * Responsibilities:
 *  1. Transport normalised fields between parser, AI service, and persistence.
 *  2. Validate critical fields (price, area, city) — determines listing visibility.
 *  3. Calculate a data-quality score (0–100) — measures data completeness.
 *  4. Resolve listing status (AVAILABLE / INCOMPLETE / FAILED).
 *  5. Provide semantic fingerprinting for deduplication.
 *
 * Usage in the pipeline:
 *  Extract → AI Normalise → `ListingDTO::fromArray()` → validate → score → save
 */
readonly class ListingDTO
{
    /** Fields that MUST be present and non-empty for a listing to be visible. */
    private const CRITICAL_FIELDS = ['price', 'area_m2', 'city'];

    /**
     * Optional fields — each missing one deducts points from quality score.
     *
     * @var array<string, int>  field_name => penalty_points
     */
    private const OPTIONAL_SCORED_FIELDS = [
        'street'      => 20,
        'rooms'       => 10,
        'description' => 10,
        'type'        => 10,
        'keywords'    => 5,
        'images'      => 5,
    ];

    public function __construct(
        public ?string $externalId,
        public string $title,
        public string $description,
        public float $price,
        public string $currency,
        public float $areaM2,
        public int $rooms,
        public string $city,
        public ?string $street,
        public PropertyType $type,
        public ListingStatus $status,
        public array $rawData,
        public ?array $images = null,
        public ?array $keywords = null,
    ) {}

    // ─── Fingerprint ───────────────────────────────────────────────

    /**
     * Calculate a semantic fingerprint for cross-platform deduplication.
     */
    public function fingerprint(): string
    {
        return FingerprintService::calculate(
            city:   $this->city,
            street: $this->street,
            price:  $this->price,
            areaM2: $this->areaM2,
            rooms:  $this->rooms,
        );
    }

    // ─── Validation ────────────────────────────────────────────────

    /**
     * Validate critical fields required for a user-visible listing.
     *
     * @return array<string, string> Field → error message (empty array = valid).
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->price <= 0) {
            $errors['price'] = 'Price is missing or zero.';
        }

        if ($this->areaM2 <= 0) {
            $errors['area_m2'] = 'Area is missing or zero.';
        }

        if (trim($this->city) === '' || $this->city === 'Unknown') {
            $errors['city'] = 'City is missing or unknown.';
        }

        return $errors;
    }

    /**
     * Whether all critical fields pass validation.
     */
    public function isValid(): bool
    {
        return $this->validate() === [];
    }

    /**
     * Determine the appropriate listing status based on validation.
     *
     * - All critical fields present  → AVAILABLE (visible to users).
     * - Some critical fields missing → INCOMPLETE (hidden from users).
     * - All critical fields missing  → FAILED (hidden, data unusable).
     */
    public function resolveStatus(): ListingStatus
    {
        $errors = $this->validate();

        if ($errors === []) {
            return ListingStatus::AVAILABLE;
        }

        if (count($errors) === count(self::CRITICAL_FIELDS)) {
            return ListingStatus::FAILED;
        }

        return ListingStatus::INCOMPLETE;
    }

    // ─── Quality Score ─────────────────────────────────────────────

    /**
     * Calculate a data-quality score (0–100).
     *
     * Starts at 100 and deducts points for each missing optional field.
     * Critical-field failures are handled by status resolution, not score.
     */
    public function qualityScore(): int
    {
        $score = 100;

        foreach (self::OPTIONAL_SCORED_FIELDS as $field => $penalty) {
            if ($this->isFieldMissing($field)) {
                $score -= $penalty;
            }
        }

        return max(0, $score);
    }

    /**
     * Whether the listing data is complete (quality score = 100).
     */
    public function isFullyParsed(): bool
    {
        return $this->qualityScore() === 100;
    }

    /**
     * Check whether an optional scored field is considered "missing".
     */
    private function isFieldMissing(string $field): bool
    {
        return match ($field) {
            'street'      => $this->street === null || trim($this->street) === '',
            'rooms'       => $this->rooms <= 0,
            'description' => trim($this->description) === '',
            'type'        => $this->type === PropertyType::UNKNOWN,
            'keywords'    => $this->keywords === null || $this->keywords === [],
            'images'      => $this->images === null || $this->images === [],
            default       => true,
        };
    }

    // ─── Keyword Normalisation ─────────────────────────────────────

    /**
     * Normalise raw keywords into unique, slugified tags.
     *
     * @param  array<int, mixed>  $raw  Raw keyword values.
     * @return list<string>             Unique slugified keywords.
     */
    public static function normalizeKeywords(array $raw): array
    {
        $normalized = [];

        foreach ($raw as $keyword) {
            $slug = Str::slug(trim((string) $keyword));
            if ($slug !== '') {
                $normalized[] = $slug;
            }
        }

        return array_values(array_unique($normalized));
    }

    // ─── Factory ───────────────────────────────────────────────────

    /**
     * Construct a ListingDTO from a raw associative array.
     *
     * Defensively casts all fields to their expected types, providing
     * sensible defaults when keys are missing.
     */
    public static function fromArray(array $data): self
    {
        $keywords = $data['keywords'] ?? null;
        if (is_array($keywords) && $keywords !== []) {
            $keywords = self::normalizeKeywords($keywords);
            $keywords = $keywords === [] ? null : $keywords;
        } else {
            $keywords = null;
        }

        return new self(
            externalId:  $data['external_id'] ?? null,
            title:       $data['title'] ?? 'Property Listing',
            description: $data['description'] ?? '',
            price:       (float) ($data['price'] ?? 0),
            currency:    $data['currency'] ?? 'PLN',
            areaM2:      (float) ($data['area_m2'] ?? 0),
            rooms:       (int) ($data['rooms'] ?? 0),
            city:        $data['city'] ?? '',
            street:      $data['street'] ?? null,
            type:        PropertyType::fromSafe($data['type'] ?? 'unknown'),
            status:      ListingStatus::from($data['status'] ?? ListingStatus::AVAILABLE->value),
            rawData:     $data['raw_data'] ?? $data,
            images:      $data['images'] ?? null,
            keywords:    $keywords,
        );
    }

    // ─── Serialisation ─────────────────────────────────────────────

    /**
     * Convert to a flat array suitable for Eloquent mass-assignment.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'external_id'     => $this->externalId,
            'fingerprint'     => $this->fingerprint(),
            'title'           => $this->title,
            'description'     => $this->description,
            'price'           => $this->price,
            'currency'        => $this->currency,
            'area_m2'         => $this->areaM2,
            'rooms'           => $this->rooms,
            'city'            => $this->city,
            'street'          => $this->street,
            'type'            => $this->type->value,
            'status'          => $this->status->value,
            'quality_score'   => $this->qualityScore(),
            'is_fully_parsed' => $this->isFullyParsed(),
            'raw_data'        => $this->rawData,
            'images'          => $this->images,
            'keywords'        => $this->keywords,
        ];
    }
}
