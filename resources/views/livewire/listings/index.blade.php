<div class="min-h-screen bg-white" @if($this->hasPendingListings) wire:poll.3s @endif>
    <section class="border-b border-zinc-200 bg-white">
        <div class="container mx-auto max-w-7xl px-6 py-12 md:py-16">
            <h1 class="text-3xl md:text-4xl font-light tracking-[0.15em] text-zinc-900">
                Browse Properties
            </h1>
            <p class="mt-3 text-sm font-light text-zinc-500 max-w-xl leading-relaxed">
                A curated catalogue of architectural spaces — filtered, normalized, and ready for your discerning eye.
            </p>
        </div>
    </section>

    <div class="container mx-auto max-w-7xl px-6 py-12">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <aside class="lg:col-span-1" x-data="{ filtersOpen: false }">
                <div class="sticky top-20 space-y-8">
                    <button
                        @click="filtersOpen = !filtersOpen"
                        class="w-full flex items-center justify-between lg:pointer-events-none"
                    >
                    <div>
                            <p class="text-[0.7rem] tracking-[0.3em] uppercase text-zinc-500 mb-2 text-left">
                            FILTERS
                        </p>
                        <h2 class="text-xl font-light tracking-[0.1em] text-zinc-900">
                            Refine Search
                        </h2>
                    </div>
                        <svg
                            class="w-5 h-5 text-zinc-400 transition-transform duration-200 lg:hidden"
                            :class="filtersOpen && 'rotate-180'"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    <div
                        x-show="filtersOpen"
                        x-cloak
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="lg:!block space-y-6"
                        wire:key="filters-form-{{ $this->formKey }}"
                    >
                        <div>
                            <label class="block text-xs uppercase tracking-wider text-zinc-600 font-light mb-2">
                                Price Range
                            </label>
                            <div class="flex items-center gap-2">
                                <input 
                                    type="number" 
                                    wire:model.live.debounce.500ms="priceMin"
                                    placeholder="Min"
                                    class="w-full px-3 py-2 text-sm border border-zinc-200 rounded-none bg-white text-zinc-900 focus:outline-none focus:border-zinc-400 transition-colors"
                                />
                                <span class="text-zinc-400">—</span>
                                <input 
                                    type="number" 
                                    wire:model.live.debounce.500ms="priceMax"
                                    placeholder="Max"
                                    class="w-full px-3 py-2 text-sm border border-zinc-200 rounded-none bg-white text-zinc-900 focus:outline-none focus:border-zinc-400 transition-colors"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs uppercase tracking-wider text-zinc-600 font-light mb-2">
                                Area (m²)
                            </label>
                            <div class="flex items-center gap-2">
                                <input 
                                    type="number" 
                                    wire:model.live.debounce.500ms="areaMin"
                                    placeholder="Min"
                                    class="w-full px-3 py-2 text-sm border border-zinc-200 rounded-none bg-white text-zinc-900 focus:outline-none focus:border-zinc-400 transition-colors"
                                />
                                <span class="text-zinc-400">—</span>
                                <input 
                                    type="number" 
                                    wire:model.live.debounce.500ms="areaMax"
                                    placeholder="Max"
                                    class="w-full px-3 py-2 text-sm border border-zinc-200 rounded-none bg-white text-zinc-900 focus:outline-none focus:border-zinc-400 transition-colors"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs uppercase tracking-wider text-zinc-600 font-light mb-2">
                                Rooms
                            </label>
                            <div class="flex items-center gap-2">
                                <input 
                                    type="number" 
                                    wire:model.live.debounce.500ms="roomsMin"
                                    placeholder="Min"
                                    class="w-full px-3 py-2 text-sm border border-zinc-200 rounded-none bg-white text-zinc-900 focus:outline-none focus:border-zinc-400 transition-colors"
                                />
                                <span class="text-zinc-400">—</span>
                                <input 
                                    type="number" 
                                    wire:model.live.debounce.500ms="roomsMax"
                                    placeholder="Max"
                                    class="w-full px-3 py-2 text-sm border border-zinc-200 rounded-none bg-white text-zinc-900 focus:outline-none focus:border-zinc-400 transition-colors"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs uppercase tracking-wider text-zinc-600 font-light mb-2">
                                City
                            </label>
                            <input 
                                type="text" 
                                wire:model.live.debounce.500ms="city"
                                placeholder="Enter city name"
                                class="w-full px-3 py-2 text-sm border border-zinc-200 rounded-none bg-white text-zinc-900 focus:outline-none focus:border-zinc-400 transition-colors"
                            />
                        </div>

                        <div>
                            <label class="block text-xs uppercase tracking-wider text-zinc-600 font-light mb-2">
                                Search
                            </label>
                            <input 
                                type="text" 
                                wire:model.live.debounce.500ms="search"
                                placeholder="Search properties..."
                                class="w-full px-3 py-2 text-sm border border-zinc-200 rounded-none bg-white text-zinc-900 focus:outline-none focus:border-zinc-400 transition-colors"
                            />
                        </div>

                        <button 
                            type="button"
                            wire:click="clearFilters"
                            wire:loading.attr="disabled"
                            wire:target="clearFilters"
                            class="w-full px-4 py-2 text-sm font-light text-zinc-600 hover:text-zinc-900 border border-zinc-200 hover:border-zinc-300 rounded-none transition-colors uppercase tracking-wider disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span wire:loading.remove wire:target="clearFilters">Clear All Filters</span>
                            <span wire:loading wire:target="clearFilters">Clearing...</span>
                        </button>
                    </div>
                </div>
            </aside>

            <main id="listings-top" class="lg:col-span-3 space-y-8">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-8 border-b border-zinc-200">
                    <div>
                        <p class="text-xs uppercase tracking-wider text-zinc-500 font-light">
                            SHOWING <span class="font-medium text-zinc-900">{{ $this->listings->total() ?? 0 }}</span> ARCHITECTURAL SPACES
                        </p>
                    </div>
                    
                    <div class="w-full md:w-auto">
                        <label class="sr-only">Sort By</label>
                        <select 
                            wire:model.live="sort"
                            wire:loading.attr="disabled"
                            wire:target="sort"
                            class="w-full md:w-auto px-4 py-2 text-sm border border-zinc-200 rounded-none bg-white text-zinc-900 focus:outline-none focus:border-zinc-400 transition-colors disabled:opacity-50"
                        >
                            <option value="newest">Newest</option>
                            <option value="oldest">Oldest</option>
                            <option value="price_high">Price: High to Low</option>
                            <option value="price_low">Price: Low to High</option>
                            <option value="area_large">Largest Area</option>
                        </select>
                    </div>
                </div>

                @if($this->hasPendingListings)
                    <div class="flex items-center gap-2 py-2">
                        <span class="w-1.5 h-1.5 bg-amber-400 rounded-full animate-pulse shrink-0"></span>
                        <p class="text-[10px] uppercase tracking-[0.25em] text-zinc-400 font-light">
                            Curating new spaces
                        </p>
                    </div>
                @endif

                @if($errors->has('filters'))
                    <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 text-sm">
                        {{ $errors->first('filters') }}
                    </div>
                @endif

                <div
                    wire:loading.delay.short
                    wire:target="sort, priceMin, priceMax, areaMin, areaMax, roomsMin, roomsMax, city, type, search, clearFilters, gotoPage, previousPage, nextPage"
                    class="relative h-px bg-zinc-200 overflow-hidden mb-6"
                >
                    <span class="absolute inset-0 bg-zinc-900 animate-progress-slide"></span>
                </div>

                <div
                    class="grid grid-cols-1 gap-4 transition-opacity duration-300"
                    wire:loading.class.delay.short="opacity-30 pointer-events-none"
                    wire:target="sort, priceMin, priceMax, areaMin, areaMax, roomsMin, roomsMax, city, type, search, clearFilters, gotoPage, previousPage, nextPage"
                    wire:key="listing-grid-{{ $this->listings->currentPage() }}-{{ $this->sort }}-{{ $this->formKey }}"
                    x-data="{ shown: false }"
                    x-init="$nextTick(() => shown = true)"
                >
                    @forelse($this->listings as $listing)
                        <div
                            class="md:h-[240px] opacity-0"
                            :class="shown && 'animate-card-enter'"
                            style="animation-delay: {{ $loop->index * 60 }}ms"
                        >
                            <livewire:listing-card
                                :listing="$listing"
                                :is-pending="$listing->isPending()"
                                :media-count="count($listing->getGalleryImageUrls())"
                                :key="'listing-' . $listing->id . '-' . count($listing->getGalleryImageUrls())"
                            />
                        </div>
                    @empty
                        {{-- Empty State --}}
                        <div class="col-span-1 text-center py-24">
                            <div class="max-w-md mx-auto space-y-4">
                                <p class="text-2xl font-light text-zinc-400 tracking-[0.1em]">
                                    NO RESULTS FOUND
                                </p>
                                <p class="text-sm text-zinc-500 font-light">
                                    Try adjusting your filters or search criteria.
                                </p>
                                <button 
                                    type="button"
                                    wire:click="clearFilters"
                                    wire:loading.attr="disabled"
                                    wire:target="clearFilters"
                                    class="mt-4 px-6 py-2 text-sm font-light text-zinc-600 hover:text-zinc-900 border border-zinc-200 hover:border-zinc-300 rounded-none transition-colors uppercase tracking-wider disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <span wire:loading.remove wire:target="clearFilters">Clear All Filters</span>
                                    <span wire:loading wire:target="clearFilters">Clearing...</span>
                                </button>
                            </div>
                        </div>
                    @endforelse
                </div>

                @if($this->listings->hasPages())
                    <div class="mt-8 flex justify-center">
                        <div class="flex items-center gap-2">
                            {{-- Previous Page Link --}}
                            @if ($this->listings->onFirstPage())
                                <span class="px-4 py-2 text-sm text-zinc-400 border border-zinc-200 rounded-none cursor-not-allowed">
                                    Previous
                                </span>
                            @else
                                <button 
                                    wire:click="previousPage('page')"
                                    x-on:click="$nextTick(() => document.getElementById('listings-top')?.scrollIntoView({ behavior: 'smooth' }))"
                                    class="px-4 py-2 text-sm font-light text-zinc-600 hover:text-zinc-900 border border-zinc-200 hover:border-zinc-300 rounded-none transition-colors"
                                >
                                    Previous
                                </button>
                            @endif
                            
                            @php
                                $currentPage = $this->listings->currentPage();
                                $lastPage = $this->listings->lastPage();
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($lastPage, $currentPage + 2);
                            @endphp
                            
                            @if($startPage > 1)
                                <button 
                                    wire:click="gotoPage(1, 'page')"
                                    x-on:click="$nextTick(() => document.getElementById('listings-top')?.scrollIntoView({ behavior: 'smooth' }))"
                                    class="px-4 py-2 text-sm font-light text-zinc-600 hover:text-zinc-900 border border-zinc-200 hover:border-zinc-300 rounded-none transition-colors"
                                >
                                    1
                                </button>
                                @if($startPage > 2)
                                    <span class="px-2 text-zinc-400">...</span>
                                @endif
                            @endif
                            
                            @for($page = $startPage; $page <= $endPage; $page++)
                                @if ($page == $currentPage)
                                    <span class="px-4 py-2 text-sm font-light bg-zinc-900 text-white border border-zinc-900 rounded-none">
                                        {{ $page }}
                                    </span>
                                @else
                                    <button 
                                        wire:click="gotoPage({{ $page }}, 'page')"
                                        x-on:click="$nextTick(() => document.getElementById('listings-top')?.scrollIntoView({ behavior: 'smooth' }))"
                                        class="px-4 py-2 text-sm font-light text-zinc-600 hover:text-zinc-900 border border-zinc-200 hover:border-zinc-300 rounded-none transition-colors"
                                    >
                                        {{ $page }}
                                    </button>
                                @endif
                            @endfor
                            
                            @if($endPage < $lastPage)
                                @if($endPage < $lastPage - 1)
                                    <span class="px-2 text-zinc-400">...</span>
                                @endif
                                <button 
                                    wire:click="gotoPage({{ $lastPage }}, 'page')"
                                    x-on:click="$nextTick(() => document.getElementById('listings-top')?.scrollIntoView({ behavior: 'smooth' }))"
                                    class="px-4 py-2 text-sm font-light text-zinc-600 hover:text-zinc-900 border border-zinc-200 hover:border-zinc-300 rounded-none transition-colors"
                                >
                                    {{ $lastPage }}
                                </button>
                            @endif
                            
                            @if ($this->listings->hasMorePages())
                                <button 
                                    wire:click="nextPage('page')"
                                    x-on:click="$nextTick(() => document.getElementById('listings-top')?.scrollIntoView({ behavior: 'smooth' }))"
                                    class="px-4 py-2 text-sm font-light text-zinc-600 hover:text-zinc-900 border border-zinc-200 hover:border-zinc-300 rounded-none transition-colors"
                                >
                                    Next
                                </button>
                            @else
                                <span class="px-4 py-2 text-sm text-zinc-400 border border-zinc-200 rounded-none cursor-not-allowed">
                                    Next
                                </span>
                            @endif
                        </div>
                    </div>
                    
                    <div class="mt-4 text-center">
                        <p class="text-xs text-zinc-500 font-light">
                            Showing {{ $this->listings->firstItem() }} to {{ $this->listings->lastItem() }} of {{ $this->listings->total() }} properties
                        </p>
                    </div>
                @endif
            </main>
        </div>
    </div>
</div>
