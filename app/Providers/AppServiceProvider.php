<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ListingProviderInterface;
use App\Services\Providers\OtodomProvider;
use App\Services\Providers\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Provider slug → concrete class.
     *
     * To add a new portal, create a class implementing ListingProviderInterface
     * and register it here — no other changes needed (OCP).
     */
    private const PROVIDER_MAP = [
        'otodom' => OtodomProvider::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class, fn () => new ProviderRegistry(
            container: $this->app,
            providerMap: self::PROVIDER_MAP,
        ));
    }

    public function boot(): void
    {
        //
    }
}
