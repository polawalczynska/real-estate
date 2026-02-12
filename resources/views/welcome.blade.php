@extends('layouts.app')

@section('title', 'U N I T - Premium Real Estate')

@section('content')
    <section class="relative w-full min-h-[60vh] md:min-h-[85vh] overflow-hidden">
        <img
            src="https://images.unsplash.com/photo-1610123172705-a57f116cd4d9?q=80&w=2070&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D"
            alt="Minimalist apartment interior"
            class="absolute inset-0 w-full h-full object-cover object-center animate-[heroZoom_25s_ease-in-out_infinite_alternate]"
        />

        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/35 to-black/20"></div>
        <div class="absolute inset-0 bg-zinc-900/15"></div>

        <div class="relative z-10 flex flex-col justify-between h-full min-h-[60vh] md:min-h-[85vh] py-10 md:py-16">
            <div class="flex-1 flex items-center">
                <div class="w-full max-w-6xl mx-auto px-6 md:px-12 lg:px-24">
                    <flux:heading
                        class="text-4xl md:text-6xl lg:text-7xl font-light tracking-[0.2em] text-white leading-tight max-w-3xl drop-shadow-sm"
                    >
                        FIND YOUR SPACE. NATURALLY.
                    </flux:heading>
                    <p class="mt-5 text-sm md:text-base font-light text-white/75 max-w-xl tracking-wide leading-relaxed">
                        Describe what you're looking for in your own words — our AI concierge handles the rest.
                    </p>
                </div>
            </div>

            <div class="pb-4 md:pb-8">
                <div class="w-full max-w-5xl mx-auto px-6 md:px-12 lg:px-24">
                    <livewire:hero-search />
                </div>
            </div>
        </div>
    </section>

    <style>
        @keyframes heroZoom {
            0%   { transform: scale(1); }
            100% { transform: scale(1.06); }
        }
    </style>

    <section class="bg-white border-t border-b border-zinc-200">
        <div class="max-w-6xl mx-auto px-6 md:px-12 lg:px-24 py-16 md:py-20 space-y-10">
            <div class="space-y-4 max-w-2xl">
                <flux:heading class="text-xs tracking-[0.25em] uppercase text-zinc-500">
                    AI-POWERED SEARCH
                </flux:heading>

                <flux:text class="text-sm md:text-base text-zinc-600 leading-relaxed font-light">
                    Skip the dropdowns. Type what you're looking for in plain language — a city, a budget, a vibe —
                    and our AI concierge translates your words into precise filters. Powered by Claude.
                </flux:text>
            </div>

            <div class="flex flex-wrap gap-3 pt-4">
                @php
                    $suggestions = [
                        'Loft in Krakow under 2 million',
                        'Spacious apartment in Warsaw with a terrace',
                        'Quiet studio in Wroclaw',
                        'House with a garden under 3M',
                    ];
                @endphp

                @foreach($suggestions as $suggestion)
                    <livewire:suggestion-tag :suggestion="$suggestion" :key="$suggestion" />
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="max-w-6xl mx-auto px-6 md:px-12 lg:px-24 py-16 md:py-20 space-y-8">
            <div class="flex items-center justify-between">
                <flux:heading class="text-[0.7rem] tracking-[0.3em] uppercase text-zinc-500">
                    LATEST LISTINGS
                </flux:heading>
                <a href="{{ route('listings.index') }}" wire:navigate class="text-xs font-light tracking-wider text-zinc-500 hover:text-zinc-900 transition-colors uppercase">
                    Browse all →
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                @forelse($featuredListings as $listing)
                    <a 
                        href="{{ route('listings.show', $listing->id) }}"
                        wire:navigate
                        class="group border border-zinc-200 rounded-none overflow-hidden bg-white flex flex-col h-full"
                    >
                        {{-- Three-state image logic --}}
                        <div class="aspect-[4/3] overflow-hidden bg-zinc-100 relative">
                            @if($listing->hasHeroImage())
                                {{-- State 1: Real image --}}
                                <img
                                    src="{{ $listing->getHeroImageUrl('card') }}"
                                    alt="{{ $listing->title }}"
                                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                    loading="lazy"
                                >
                            @elseif($listing->isPending())
                                {{-- State 2: Processing — skeleton loader --}}
                                <x-ui.placeholder-image
                                    class="w-full h-full"
                                    label="Processing visuals…"
                                    :spinning="true"
                                />
                            @else
                                {{-- State 3: No images — static placeholder --}}
                                <x-ui.placeholder-image class="w-full h-full" :animated="false" />
                            @endif
                        </div>
                        <div class="p-5 flex-1 flex flex-col justify-between space-y-3">
                            <div class="space-y-2">
                                <p class="text-xs uppercase tracking-[0.25em] text-zinc-500">
                                    {{ $listing->city }}@if($listing->street), {{ $listing->street }}@endif
                                </p>
                                <p class="text-sm font-light text-zinc-900 line-clamp-2">
                                    {{ $listing->title }}
                                </p>
                            </div>
                            <div class="flex items-center justify-between text-xs text-zinc-600 pt-3 border-t border-zinc-200">
                                <span class="text-sm font-semibold text-zinc-900">
                                    {{ number_format($listing->price, 0, '.', ' ') }} {{ $listing->currency }}
                                </span>
                                <span class="flex items-center gap-2">
                                    <span>{{ number_format($listing->area_m2, 0) }}m²</span>
                                    <span>•</span>
                                    <span>{{ $listing->rooms }} {{ $listing->rooms === 1 ? 'room' : 'rooms' }}</span>
                                </span>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="col-span-3 text-center py-12">
                        <p class="text-zinc-500 text-sm font-light">No listings available yet.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    <section class="bg-white border-y border-zinc-200">
        <div class="max-w-6xl mx-auto px-6 md:px-12 lg:px-24 py-16 md:py-20 space-y-10">
            <flux:heading class="text-xs tracking-[0.25em] uppercase text-zinc-500">
                HOW IT WORKS
            </flux:heading>

            <div class="grid grid-cols-1 md:grid-cols-3 md:divide-x divide-zinc-200 gap-10 md:gap-0">
                <div class="space-y-3 md:pr-8">
                    <p class="text-xs tracking-[0.2em] uppercase text-zinc-400 font-light">01</p>
                    <flux:heading class="text-xs tracking-[0.25em] uppercase text-zinc-600">
                        SCRAPE & IMPORT
                    </flux:heading>
                    <flux:text class="text-sm text-zinc-600 leading-relaxed font-light">
                        Listings are gathered from leading real estate portals.
                        Images, metadata, and descriptions are captured and processed automatically.
                    </flux:text>
                </div>

                <div class="space-y-3 md:px-8">
                    <p class="text-xs tracking-[0.2em] uppercase text-zinc-400 font-light">02</p>
                    <flux:heading class="text-xs tracking-[0.25em] uppercase text-zinc-600">
                        AI NORMALIZATION
                    </flux:heading>
                    <flux:text class="text-sm text-zinc-600 leading-relaxed font-light">
                        Each listing is parsed by Claude AI to extract structured data — price, area, rooms, city,
                        and description — translated to English and de-duplicated.
                    </flux:text>
                </div>

                <div class="space-y-3 md:pl-8">
                    <p class="text-xs tracking-[0.2em] uppercase text-zinc-400 font-light">03</p>
                    <flux:heading class="text-xs tracking-[0.25em] uppercase text-zinc-600">
                        IMAGE CURATION
                    </flux:heading>
                    <flux:text class="text-sm text-zinc-600 leading-relaxed font-light">
                        AI selects the 5 best photos per listing — prioritizing exteriors, living rooms,
                        and architectural details. Floor plans and low-quality shots are excluded automatically.
                    </flux:text>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="max-w-6xl mx-auto px-6 md:px-12 lg:px-24 py-16 md:py-20">
            <div class="max-w-2xl space-y-6">
                <flux:heading class="text-xs tracking-[0.25em] uppercase text-zinc-500">
                    AI CONCIERGE
                </flux:heading>
                <p class="text-lg md:text-xl font-light text-zinc-800 leading-relaxed">
                    Not sure what you're looking for? Open the Concierge chat — describe a neighbourhood,
                    a price range, a feeling — and get curated results in seconds.
                </p>
                <p class="text-sm font-light text-zinc-500 leading-relaxed">
                    Click the <span class="inline-flex items-center gap-1 px-2 py-0.5 border border-zinc-300 text-zinc-700 text-xs tracking-wider uppercase">Concierge</span>
                    button in the bottom-right corner to start a conversation.
                </p>
            </div>
        </div>
    </section>

@endsection
