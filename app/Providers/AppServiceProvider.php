<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AiNormalizerInterface;
use App\Contracts\AiSearchInterface;
use App\Contracts\ImageAttacherInterface;
use App\Contracts\ListingProviderInterface;
use App\Contracts\ProviderRegistryInterface;
use App\Contracts\SearchServiceInterface;
use App\Services\Ai\AiNormalizationService;
use App\Services\Ai\AiSearchService;
use App\Services\ListingImageService;
use App\Services\Providers\OtodomProvider;
use App\Services\Providers\ProviderRegistry;
use App\Services\SearchService;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public array $bindings = [
        AiNormalizerInterface::class    => AiNormalizationService::class,
        AiSearchInterface::class        => AiSearchService::class,
        ImageAttacherInterface::class   => ListingImageService::class,
        ListingProviderInterface::class => OtodomProvider::class,
        SearchServiceInterface::class   => SearchService::class,
    ];

    private const PROVIDER_MAP = [
        'otodom' => OtodomProvider::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ProviderRegistryInterface::class, fn () => new ProviderRegistry(
            container: $this->app,
            providerMap: self::PROVIDER_MAP,
        ));
    }

    public function boot(): void
    {
        //
    }
}
