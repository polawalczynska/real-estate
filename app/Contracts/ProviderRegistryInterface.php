<?php

declare(strict_types=1);

namespace App\Contracts;

interface ProviderRegistryInterface
{
    /**
     * Resolve a listing provider by its slug (e.g. "otodom", "morizon").
     */
    public function resolve(string $providerName): ?ListingProviderInterface;

    /**
     * Return all registered provider slugs.
     *
     * @return list<string>
     */
    public function available(): array;
}
