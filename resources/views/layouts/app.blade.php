<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'U N I T')</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-white text-zinc-900 antialiased">
    <header
        x-data="{ mobileNav: false }"
        class="border-b border-zinc-200 bg-white sticky top-0 z-50"
    >
        <div class="container mx-auto px-6 py-5 md:py-6">
            <div class="flex items-center justify-between">
                <a href="{{ route('home') }}" wire:navigate class="text-2xl font-light tracking-[0.3em] text-zinc-900 hover:text-zinc-700 transition-colors">
                    U N I T
                </a>

                <div class="hidden md:flex items-center gap-8">
                            <a href="{{ route('listings.index') }}" wire:navigate class="text-sm font-light text-zinc-600 hover:text-zinc-900 transition-colors">
                                Browse Properties
                            </a>
                    <flux:button variant="ghost" size="sm">
                        Sign In
                    </flux:button>
                </div>

                <button
                    @click="mobileNav = !mobileNav"
                    class="md:hidden flex items-center justify-center w-10 h-10 text-zinc-900"
                    aria-label="Toggle navigation"
                >
                    <svg x-show="!mobileNav" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                    </svg>
                    <svg x-show="mobileNav" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <div
            x-show="mobileNav"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2"
            class="md:hidden border-t border-zinc-100 bg-white"
        >
            <nav class="container mx-auto px-6 py-6 space-y-4">
                <a
                    href="{{ route('home') }}"
                    wire:navigate
                    @click="mobileNav = false"
                    class="block text-sm font-light text-zinc-600 hover:text-zinc-900 transition-colors tracking-wide"
                >
                    Home
                </a>
                <a
                    href="{{ route('listings.index') }}"
                    wire:navigate
                    @click="mobileNav = false"
                    class="block text-sm font-light text-zinc-600 hover:text-zinc-900 transition-colors tracking-wide"
                >
                    Browse Properties
                </a>
            </nav>
        </div>
    </header>

    <main class="min-h-screen">
        @yield('content')
        {{ $slot ?? '' }}
    </main>

    <footer class="border-t border-zinc-200 bg-white">
        <div class="container mx-auto px-6 py-10">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                <a href="{{ route('home') }}" wire:navigate class="text-lg font-light tracking-[0.3em] text-zinc-400">
                    U N I T
                </a>
                <p class="text-xs font-light text-zinc-400">
                    &copy; {{ date('Y') }} U N I T &mdash; Curated real estate, powered by AI.
                </p>
            </div>
        </div>
    </footer>

    @persist('ai-concierge')
        <livewire:ai-concierge-chat />
    @endpersist
</body>
</html>
