<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Ai\AiSearchService;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class HeroSearch extends Component
{
    public string $query = '';

    #[Locked]
    public bool $loading = false;

    public function handleSearch(): void
    {
        $query = trim($this->query);

        if ($query === '') {
            return;
        }

        $this->loading = true;

        try {
            $criteria = app(AiSearchService::class)->parseIntent($query);
            $params   = $criteria->toQueryParams();

            $this->redirect(route('listings.index', $params), navigate: true);
        } catch (Throwable $e) {
            Log::error('HeroSearch: Failed to parse intent', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            $this->redirect(route('listings.index', ['search' => $query]), navigate: true);
        }
    }

    public function searchWithQuery(string $query): void
    {
        $this->query = $query;
        $this->handleSearch();
    }

    public function render(): View
    {
        return view('livewire.hero-search');
    }
}
