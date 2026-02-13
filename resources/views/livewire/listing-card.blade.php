<div
    @class([
        'group cursor-pointer border border-zinc-200 rounded-none overflow-hidden bg-white h-full',
        'animate-card-reveal' => $justActivated ?? false,
    ])
>
    @if($listing->isPending())
        <div class="flex flex-col md:flex-row h-full">
            <x-ui.placeholder-image
                class="w-full md:w-2/5 md:h-full aspect-[16/10] md:aspect-auto shrink-0"
                label="Processing visuals…"
                :spinning="true"
            />

            <div class="flex-1 p-4 md:p-6 flex flex-col justify-between">
                <div class="space-y-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-block w-1.5 h-1.5 bg-amber-400 rounded-full animate-pulse"></span>
                        <span class="text-[10px] uppercase tracking-[0.15em] text-amber-600 font-medium">Processing offer…</span>
                    </div>

                    <p class="text-sm font-light text-zinc-500 line-clamp-2">
                        {{ $listing->title }}
                    </p>
                    <div class="h-7 bg-zinc-100 rounded w-2/5 animate-pulse"></div>
                    <div class="h-3 bg-zinc-50 rounded w-1/3 animate-pulse"></div>
                    <div class="flex gap-1.5 pt-1">
                        <div class="h-5 w-14 bg-zinc-50 rounded animate-pulse"></div>
                        <div class="h-5 w-10 bg-zinc-50 rounded animate-pulse"></div>
                        <div class="h-5 w-16 bg-zinc-50 rounded animate-pulse"></div>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-4 border-t border-zinc-100 mt-auto">
                    <div class="h-3 bg-zinc-100 rounded w-12 animate-pulse"></div>
                    <div class="h-3 bg-zinc-100 rounded w-14 animate-pulse"></div>
                    <div class="h-3 bg-zinc-100 rounded w-20 animate-pulse"></div>
                </div>
            </div>
        </div>
    @else
        <a href="{{ route('listings.show', $listing->id) }}" wire:navigate class="flex flex-col md:flex-row h-full">
            <div
                class="w-full md:w-2/5 md:h-full aspect-[16/10] md:aspect-auto overflow-hidden bg-zinc-50 shrink-0 relative"
                wire:key="listing-media-{{ $listing->id }}-{{ $mediaCount }}"
            >
                @if($listing->hasHeroImage())
                <img
                    src="{{ $listing->getHeroImageUrl('card') }}"
                    alt="{{ $listing->title }}"
                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                    loading="lazy"
                >
                @elseif($mediaCount === 0)
                    <x-ui.placeholder-image
                        class="w-full h-full"
                        label="AI preparing gallery…"
                        :spinning="true"
                    />
                @else
                    <x-ui.placeholder-image class="w-full h-full" :animated="false" />
                @endif
            </div>

            <div class="flex-1 p-4 md:p-6 flex flex-col justify-between">
                <div class="space-y-2 md:space-y-3">
                    <p class="text-2xl md:text-3xl font-semibold text-zinc-900">
                        {{ number_format($listing->price, 0, '.', ' ') }} {{ $listing->currency }}
                    </p>

                    <p class="text-sm font-light text-zinc-900 line-clamp-2">
                        {{ $listing->title }}
                    </p>

                    <p class="text-xs uppercase tracking-wider text-zinc-600 font-light">
                        @if($listing->street)
                            {{ $listing->street }}, {{ $listing->city }}
                        @else
                            {{ $listing->city }}
                        @endif
                    </p>

                    @if(!empty($listing->keywords))
                        <div class="flex flex-wrap gap-1.5 pt-1">
                            @foreach(array_slice($listing->keywords, 0, 4) as $keyword)
                                <span class="inline-block px-2 py-0.5 text-[10px] tracking-wide text-zinc-500 border border-zinc-200 bg-zinc-50 font-light">
                                    {{ $keyword }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex items-center gap-4 text-xs text-zinc-500 pt-3 md:pt-4 border-t border-zinc-200 mt-3 md:mt-auto">
                    <span>{{ number_format($listing->area_m2, 0) }}m²</span>
                    <span>•</span>
                    <span>{{ $listing->rooms }} {{ $listing->rooms === 1 ? 'room' : 'rooms' }}</span>
                    <span>•</span>
                    <span class="uppercase">{{ $listing->type->label() }}</span>
                </div>
            </div>
        </a>
    @endif
</div>

@pushOnce('styles')
<style>
    @keyframes shimmer {
        0%   { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }
    .animate-shimmer {
        animation: shimmer 2s ease-in-out infinite;
    }

    @keyframes cardReveal {
        from {
            opacity: 0;
            transform: translateY(6px) scale(0.99);
            filter: blur(2px);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
            filter: blur(0);
        }
    }
    .animate-card-reveal {
        animation: cardReveal 700ms cubic-bezier(.22, 1, .36, 1) both;
    }
</style>
@endPushOnce
