<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Listing;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class ListingCard extends Component
{
    #[Locked]
    public Listing $listing;

    #[Reactive]
    public bool $isPending = false;

    #[Reactive]
    public int $mediaCount = 0;

    public bool $wasPending = false;

    public function mount(Listing $listing, bool $isPending = false, int $mediaCount = 0): void
    {
        $this->listing = $listing;
        $this->isPending = $isPending;
        $this->mediaCount = $mediaCount;
        $this->wasPending = $isPending;
    }

    public function render(): View
    {
        $justActivated = $this->wasPending && ! $this->isPending;
        $this->wasPending = $this->isPending;
        $this->listing->load('media');

        return view('livewire.listing-card', [
            'justActivated' => $justActivated,
        ]);
    }
}
