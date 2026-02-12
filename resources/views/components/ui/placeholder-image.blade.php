@props([
    'animated' => true,
    'iconSize' => 'md',
    'label'    => null,
    'spinning' => false,
])

@php
    $sizeMap = [
        'xs' => 'w-5 h-5',
        'sm' => 'w-6 h-6',
        'md' => 'w-8 h-8',
        'lg' => 'w-12 h-12',
    ];
    $iconClass = $sizeMap[$iconSize] ?? $sizeMap['md'];
@endphp

<div {{ $attributes->merge([
    'class' => 'relative overflow-hidden bg-zinc-100'
              . ($animated && $label ? ' animate-pulse' : ''),
]) }}>

    @if($animated && ! $label)
        <div class="absolute inset-0 animate-shimmer bg-gradient-to-r from-transparent via-zinc-200/60 to-transparent pointer-events-none"></div>
    @endif

    <div class="absolute inset-0 flex flex-col items-center justify-center gap-3 px-4">
        <svg
            class="{{ $iconClass }} text-zinc-300"
            fill="none"
            stroke="currentColor"
            stroke-width="1"
            viewBox="0 0 24 24"
            aria-hidden="true"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z"
            />
        </svg>

        @if($label)
            <div class="flex items-center gap-2">
                @if($spinning)
                    <svg
                        class="w-3 h-3 text-zinc-400 animate-spin"
                        fill="none"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                @endif
                <span class="text-[10px] font-medium text-zinc-400 uppercase tracking-[0.15em]">
                    {{ $label }}
                </span>
            </div>
        @endif
    </div>
</div>
