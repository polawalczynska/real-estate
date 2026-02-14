<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\ListingProviderInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Config-driven provider registry.
 *
 * Maps provider slugs (e.g. "otodom") to concrete ListingProviderInterface
 * implementations. Adding a new portal requires only a new class + a map entry
 * in AppServiceProvider::PROVIDER_MAP.
 */
final class ProviderRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly array $providerMap,
    ) {}

    public function resolve(string $providerName): ?ListingProviderInterface
    {
        $concrete = $this->providerMap[$providerName] ?? null;

        if ($concrete === null) {
            return null;
        }

        return $this->container->make($concrete);
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return array_keys($this->providerMap);
    }
}
