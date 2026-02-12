<?php

declare(strict_types=1);

namespace App\Livewire\Listings;

use App\Contracts\SearchServiceInterface;
use App\DTOs\SearchCriteriaDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    use WithPagination;

    #[Locked]
    public int $formKey = 0;

    #[Url(as: 'price_min', history: true, keep: false)]
    public ?string $priceMin = null;

    #[Url(as: 'price_max', history: true, keep: false)]
    public ?string $priceMax = null;

    #[Url(as: 'area_min', history: true, keep: false)]
    public ?string $areaMin = null;

    #[Url(as: 'area_max', history: true, keep: false)]
    public ?string $areaMax = null;

    #[Url(as: 'rooms_min', history: true, keep: false)]
    public ?string $roomsMin = null;

    #[Url(as: 'rooms_max', history: true, keep: false)]
    public ?string $roomsMax = null;

    #[Url(as: 'city', history: true, keep: false)]
    public ?string $city = null;

    #[Url(as: 'type', history: true, keep: false)]
    public ?string $type = null;

    #[Url(as: 'search', history: true, keep: false)]
    public ?string $search = null;

    #[Url(as: 'keywords', history: true, keep: false)]
    public ?string $keywords = null;

    #[Url(as: 'sort', history: true, keep: false)]
    public string $sort = 'newest';

    private function getSearchService(): SearchServiceInterface
    {
        return app(SearchServiceInterface::class);
    }

    #[Computed]
    public function criteria(): SearchCriteriaDTO
    {
        $sortMapping = [
            'newest' => ['sort_by' => 'created_at', 'sort_order' => 'desc'],
            'oldest' => ['sort_by' => 'created_at', 'sort_order' => 'asc'],
            'price_high' => ['sort_by' => 'price', 'sort_order' => 'desc'],
            'price_low' => ['sort_by' => 'price', 'sort_order' => 'asc'],
            'area_large' => ['sort_by' => 'area_m2', 'sort_order' => 'desc'],
        ];

        $sortConfig = $sortMapping[$this->sort] ?? $sortMapping['newest'];

        $keywordArray = null;
        if ($this->keywords !== null && $this->keywords !== '') {
            $keywordArray = array_values(array_filter(
                array_map('trim', explode(',', $this->keywords)),
                static fn (string $v): bool => $v !== '',
            ));
            $keywordArray = $keywordArray === [] ? null : $keywordArray;
        }

        return SearchCriteriaDTO::fromArray([
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'area_min' => $this->areaMin,
            'area_max' => $this->areaMax,
            'rooms_min' => $this->roomsMin,
            'rooms_max' => $this->roomsMax,
            'city' => $this->city,
            'type' => $this->type,
            'search' => $this->search,
            'keywords' => $keywordArray,
            'sort_by' => $sortConfig['sort_by'],
            'sort_order' => $sortConfig['sort_order'],
            'per_page' => 15,
        ]);
    }

    #[Computed]
    public function listings(): LengthAwarePaginator
    {
        try {
            return $this->getSearchService()->search($this->criteria);
        } catch (ValidationException $e) {
            $this->addError('filters', $e->getMessage());
            return $this->getSearchService()->search(SearchCriteriaDTO::empty());
        } catch (Throwable) {
            return $this->getSearchService()->search(SearchCriteriaDTO::empty());
        }
    }

    public function updatedPriceMin(): void
    {
        $this->resetPage();
    }

    public function updatedPriceMax(): void
    {
        $this->resetPage();
    }

    public function updatedAreaMin(): void
    {
        $this->resetPage();
    }

    public function updatedAreaMax(): void
    {
        $this->resetPage();
    }

    public function updatedRoomsMin(): void
    {
        $this->resetPage();
    }

    public function updatedRoomsMax(): void
    {
        $this->resetPage();
    }

    public function updatedCity(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'priceMin',
            'priceMax',
            'areaMin',
            'areaMax',
            'roomsMin',
            'roomsMax',
            'city',
            'type',
            'search',
            'keywords',
        ]);
        
        $this->sort = 'newest';
        $this->resetPage();
        
        $this->formKey++;
    }

    #[Computed]
    public function hasPendingListings(): bool
    {
        return DB::table('jobs')
            ->whereIn('queue', ['ai', 'media'])
            ->exists();
    }

    public function render(): View
    {
        return view('livewire.listings.index')
            ->layout('layouts.app', [
                'title' => 'Browse Properties - U N I T',
            ]);
    }
}
