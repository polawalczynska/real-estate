<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SuggestionTag extends Component
{
    #[Locked]
    public string $suggestion = '';

    public function search(): void
    {
        $this->dispatch('suggestion-selected', suggestion: $this->suggestion);
    }

    public function render(): View
    {
        return view('livewire.suggestion-tag');
    }
}
