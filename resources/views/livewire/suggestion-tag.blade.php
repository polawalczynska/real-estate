<button
    type="button"
    wire:click="search"
    wire:loading.attr="disabled"
    class="px-4 py-2 rounded-none border border-zinc-200 bg-white text-xs md:text-sm font-light tracking-wide text-zinc-700 hover:bg-zinc-50 hover:border-zinc-300 transition-colors disabled:opacity-50 disabled:cursor-wait"
>
    <span wire:loading.remove>"{{ $suggestion }}"</span>
    <span wire:loading class="flex items-center gap-2">
        <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        "{{ $suggestion }}"
    </span>
</button>
