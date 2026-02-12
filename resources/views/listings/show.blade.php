@extends('layouts.app')

@section('title', $listing->title . ' — U N I T')

@section('content')
    @php
        $isPending    = $listing->isPending();
        $gallery      = $listing->getMedia('gallery');
        $heroMedia    = $listing->getHeroMedia();
        $detailImages = $gallery->filter(fn ($m) => $m->id !== $heroMedia?->id)->take(4);
        $offerUrl     = $listing->raw_data['url'] ?? null;
        $hasHero      = $listing->hasHeroImage();
        $mediaCount   = $gallery->count();
    @endphp

    <div class="container mx-auto max-w-7xl px-6 pt-8">
        <a href="{{ url()->previous(route('listings.index')) }}" wire:navigate class="inline-flex items-center gap-2 text-xs uppercase tracking-wider text-zinc-400 hover:text-zinc-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
            Back to listings
        </a>
    </div>

    @if($isPending)
        <div class="container mx-auto max-w-7xl px-6 pt-6" id="pending-banner">
            <div class="flex items-center gap-3 p-5 border border-amber-200 bg-amber-50/50">
                <span class="w-2 h-2 bg-amber-400 rounded-full animate-pulse shrink-0"></span>
                <div>
                    <p class="text-sm font-medium text-amber-800">Archiving in progress</p>
                    <p class="text-xs font-light text-amber-600 mt-0.5">
                        AI is analyzing this property — details, images, and features will appear shortly.
                    </p>
                </div>
            </div>
        </div>
        <script>
            setTimeout(function refresh() {
                fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(() => window.location.reload())
                    .catch(() => setTimeout(refresh, 8000));
            }, 8000);
        </script>
    @endif

    <div class="container mx-auto max-w-7xl px-6 pt-6">
        <div class="relative aspect-[21/9] overflow-hidden bg-zinc-100">
            @if($hasHero)
                <img
                    src="{{ $listing->getHeroImageUrl('hero') }}"
                    alt="{{ $listing->title }}"
                    class="w-full h-full object-cover"
                    loading="eager"
                >
            @elseif($isPending || $mediaCount === 0)
                <x-ui.placeholder-image
                    class="w-full h-full"
                    icon-size="lg"
                    label="Processing visuals…"
                    :spinning="true"
                />
            @else
                <x-ui.placeholder-image
                    class="w-full h-full"
                    icon-size="lg"
                    :animated="false"
                />
            @endif

            <span class="absolute top-6 left-6 z-20 bg-white/90 backdrop-blur-sm px-4 py-1.5 text-[10px] uppercase tracking-[0.2em] text-zinc-700 font-medium">
                {{ $listing->type->label() }}
            </span>
        </div>
    </div>

    <div class="container mx-auto max-w-7xl px-6 pt-10 pb-8">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
            <div class="space-y-2">
                <h1 class="text-3xl md:text-4xl font-light tracking-tight text-zinc-900">
                    {{ $listing->title }}
                </h1>
                @if(!$isPending && $listing->city)
                    <p class="text-sm font-light text-zinc-500 tracking-wide">
                        {{ $listing->city }}@if($listing->street), {{ $listing->street }}@endif
                    </p>
                @endif
            </div>
            @if(!$isPending && $listing->price > 0)
                <p class="text-3xl md:text-4xl font-light text-zinc-900 whitespace-nowrap">
                    {{ number_format($listing->price, 0, '.', ' ') }}
                    <span class="text-lg text-zinc-400 font-light">{{ $listing->currency }}</span>
                </p>
            @elseif($isPending)
                <div class="flex items-center gap-2">
                    <div class="h-8 w-40 bg-zinc-100 rounded animate-pulse"></div>
                </div>
            @endif
        </div>
    </div>

    @if($isPending)
        <div class="border-y border-zinc-200">
            <div class="container mx-auto max-w-7xl px-6">
                <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-zinc-200">
                    @foreach(['Area', 'Rooms', 'Price / m²', 'Status'] as $label)
                        <div class="py-6 text-center">
                            <p class="text-[10px] uppercase tracking-[0.2em] text-zinc-400 mb-1">{{ $label }}</p>
                            @if($label === 'Status')
                                <p class="text-xl font-light text-amber-600">Pending</p>
                            @else
                                <div class="h-6 w-16 mx-auto bg-zinc-100 rounded animate-pulse"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        <div class="border-y border-zinc-200">
            <div class="container mx-auto max-w-7xl px-6">
                <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-zinc-200">
                    <div class="py-6 text-center">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-zinc-400 mb-1">Area</p>
                        <p class="text-xl font-light text-zinc-900">{{ $listing->area_m2 == (int) $listing->area_m2 ? number_format($listing->area_m2, 0) : number_format($listing->area_m2, 1) }} <span class="text-sm text-zinc-400">m²</span></p>
                    </div>
                    <div class="py-6 text-center">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-zinc-400 mb-1">Rooms</p>
                        <p class="text-xl font-light text-zinc-900">{{ $listing->rooms }}</p>
                    </div>
                    <div class="py-6 text-center">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-zinc-400 mb-1">Price / m²</p>
                        <p class="text-xl font-light text-zinc-900">{{ $listing->area_m2 > 0 ? number_format($listing->price / $listing->area_m2, 0, '.', ' ') : '—' }} <span class="text-sm text-zinc-400">{{ $listing->currency }}</span></p>
                    </div>
                    <div class="py-6 text-center">
                        <p class="text-[10px] uppercase tracking-[0.2em] text-zinc-400 mb-1">Status</p>
                        <p class="text-xl font-light text-zinc-900">{{ $listing->status->label() }}</p>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="container mx-auto max-w-7xl px-6 py-12">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-16">
            <div class="lg:col-span-3 space-y-12">
                <div>
                    <h2 class="text-xs uppercase tracking-[0.2em] text-zinc-400 mb-4">About this property</h2>
                    @if($isPending)
                        <div class="space-y-3">
                            <div class="h-4 bg-zinc-100 rounded w-full animate-pulse"></div>
                            <div class="h-4 bg-zinc-100 rounded w-5/6 animate-pulse"></div>
                            <div class="h-4 bg-zinc-50 rounded w-4/6 animate-pulse"></div>
                            <p class="text-xs text-zinc-400 font-light tracking-wide mt-4">
                                Description will be extracted by AI shortly...
                            </p>
                        </div>
                    @else
                        <p class="text-base leading-relaxed text-zinc-600 font-light">
                            {{ $listing->description }}
                        </p>
                    @endif
                </div>

                @if(!empty($listing->keywords))
                    <div>
                        <h2 class="text-xs uppercase tracking-[0.2em] text-zinc-400 mb-4">Features</h2>
                        <div class="flex flex-wrap gap-2">
                            @foreach($listing->keywords as $keyword)
                                <span class="inline-block px-3 py-1.5 text-xs tracking-wide text-zinc-600 border border-zinc-200 bg-zinc-50 font-light">
                                    {{ $keyword }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($isPending)
                    <div>
                        <h2 class="text-xs uppercase tracking-[0.2em] text-zinc-400 mb-4">Gallery</h2>
                        <div class="grid grid-cols-2 gap-3">
                            @for($i = 0; $i < 4; $i++)
                                <x-ui.placeholder-image
                                    class="aspect-[4/3]"
                                    icon-size="sm"
                                    :label="$i === 0 ? 'AI curating photos…' : null"
                                    :spinning="$i === 0"
                                />
                            @endfor
                        </div>
                    </div>
                @elseif($detailImages->isNotEmpty())
                    <div>
                        <h2 class="text-xs uppercase tracking-[0.2em] text-zinc-400 mb-4">Gallery</h2>
                        <div class="grid grid-cols-2 gap-3">
                            @foreach($detailImages as $media)
                                <div class="aspect-[4/3] overflow-hidden bg-zinc-100">
                                    <img
                                        src="{{ $media->getUrl('card') ?: $media->getUrl() }}"
                                        alt="{{ $listing->title }}"
                                        class="w-full h-full object-cover hover:scale-105 transition-transform duration-500"
                                        loading="lazy"
                                    >
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="lg:col-span-2">
                <div class="sticky top-28 space-y-6">
                    <div class="border border-zinc-200 p-8 space-y-5">
                        <h3 class="text-xs uppercase tracking-[0.2em] text-zinc-400">Details</h3>
                        @if($isPending)
                            <div class="space-y-4">
                                @foreach(['Type', 'Area', 'Rooms', 'City', 'Price / m²'] as $label)
                                    <div class="flex justify-between gap-4 @if(!$loop->first) border-t border-zinc-100 pt-4 @endif">
                                        <dt class="text-zinc-400 font-light shrink-0">{{ $label }}</dt>
                                        <dd><div class="h-4 w-20 bg-zinc-100 rounded animate-pulse"></div></dd>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <dl class="space-y-4 text-sm">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-zinc-400 font-light shrink-0">Type</dt>
                                    <dd class="text-zinc-900 text-right">{{ $listing->type->label() }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 border-t border-zinc-100 pt-4">
                                    <dt class="text-zinc-400 font-light shrink-0">Area</dt>
                                    <dd class="text-zinc-900 text-right">{{ $listing->area_m2 == (int) $listing->area_m2 ? number_format($listing->area_m2, 0) : number_format($listing->area_m2, 1) }} m²</dd>
                                </div>
                                <div class="flex justify-between gap-4 border-t border-zinc-100 pt-4">
                                    <dt class="text-zinc-400 font-light shrink-0">Rooms</dt>
                                    <dd class="text-zinc-900 text-right">{{ $listing->rooms }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 border-t border-zinc-100 pt-4">
                                    <dt class="text-zinc-400 font-light shrink-0">City</dt>
                                    <dd class="text-zinc-900 text-right">{{ $listing->city }}</dd>
                                </div>
                                @if($listing->street)
                                    <div class="flex justify-between gap-4 border-t border-zinc-100 pt-4">
                                        <dt class="text-zinc-400 font-light shrink-0">Street</dt>
                                        <dd class="text-zinc-900 text-right">{{ $listing->street }}</dd>
                                    </div>
                                @endif
                                <div class="flex justify-between gap-4 border-t border-zinc-100 pt-4">
                                    <dt class="text-zinc-400 font-light shrink-0">Price / m²</dt>
                                    <dd class="text-zinc-900 text-right whitespace-nowrap">{{ $listing->area_m2 > 0 ? number_format($listing->price / $listing->area_m2, 0, '.', ' ') : '—' }} {{ $listing->currency }}</dd>
                                </div>
                            </dl>
                        @endif
                    </div>

                    @if($offerUrl)
                        <a
                            href="{{ $offerUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex items-center justify-center gap-2 w-full bg-zinc-900 text-white text-sm tracking-wider uppercase py-4 hover:bg-zinc-800 transition-colors"
                        >
                            View original offer
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-4.5-4.5h6m0 0v6m0-6L10.5 13.5"/></svg>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
