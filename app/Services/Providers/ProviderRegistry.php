<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\ListingProviderInterface;
use App\Contracts\ProviderRegistryInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Config-driven provider registry.
 */
final class ProviderRegistry implements ProviderRegistryInterface
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

    public function available(): array
    {
        return array_keys($this->providerMap);
    }
}
