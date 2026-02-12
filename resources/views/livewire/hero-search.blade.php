<div
    class="w-full"
    x-data="{
        displayQuery: '',
        isTyping: false,
        typeVersion: 0,

        async typeOut(text) {
            const version = ++this.typeVersion;
            this.isTyping = true;
            this.displayQuery = '';

            this.$refs.searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });

            await new Promise(r => setTimeout(r, 350));
            if (this.typeVersion !== version) return;

            for (let i = 0; i < text.length; i++) {
                if (this.typeVersion !== version) return;
                this.displayQuery += text[i];
                await new Promise(r => setTimeout(r, 28 + Math.random() * 24));
            }

            if (this.typeVersion !== version) return;

            await new Promise(r => setTimeout(r, 500));
            if (this.typeVersion !== version) return;

            this.isTyping = false;
            $wire.searchWithQuery(text);
        },

        submitSearch() {
            if (this.isTyping) return;
            $wire.set('query', this.displayQuery, false);
            $wire.handleSearch();
        }
    }"
    @suggestion-selected.window="typeOut($event.detail.suggestion)"
>
    <form @submit.prevent="submitSearch()" class="relative">
        <input
            x-ref="searchInput"
            type="text"
            x-model="displayQuery"
            placeholder="Describe your ideal space — a sun-drenched loft, a quiet penthouse..."
            class="w-full bg-white/75 backdrop-blur-md border border-zinc-200/80 text-zinc-900 placeholder:text-zinc-500 text-sm md:text-base font-light tracking-wide rounded-none shadow-sm focus:outline-none focus:ring-0 focus:border-zinc-400/80 pl-4 pr-12 py-3"
            :class="isTyping && 'caret-transparent'"
            :disabled="isTyping"
            wire:loading.attr="disabled"
            wire:target="searchWithQuery,handleSearch"
        />

        <button
            type="submit"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-900 transition-colors"
            :disabled="isTyping"
            wire:loading.attr="disabled"
            wire:target="searchWithQuery,handleSearch"
        >
            <span wire:loading wire:target="searchWithQuery,handleSearch">
                <svg class="animate-spin h-5 w-5 text-zinc-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </span>
            <span wire:loading.remove wire:target="searchWithQuery,handleSearch">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
            </span>
        </button>
    </form>

    <div wire:loading wire:target="searchWithQuery,handleSearch" class="mt-3">
        <p class="text-xs font-light tracking-wider text-white/80 animate-pulse">
            CONSULTING CONCIERGE...
        </p>
    </div>

    <div x-show="isTyping" x-cloak class="mt-3">
        <span class="inline-block w-[3px] h-3.5 bg-white/70 animate-pulse"></span>
    </div>
</div>
