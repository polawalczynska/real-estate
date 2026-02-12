<div x-data="{ drawerOpen: $wire.entangle('open') }">
    <button
        x-show="!drawerOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-cloak
        wire:click="openChat"
        class="fixed bottom-8 right-8 z-50 flex items-center gap-2 px-5 py-3 bg-zinc-900 text-white text-xs font-light tracking-[0.2em] uppercase hover:bg-zinc-800 transition-colors shadow-lg border border-zinc-700"
    >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
        </svg>
        Concierge
    </button>

    <div
        x-show="drawerOpen"
        x-cloak
        class="fixed inset-0 z-50 flex justify-end"
    >
        <div
            x-show="drawerOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="drawerOpen = false; $wire.closeChat()"
            class="absolute inset-0 bg-black/20 backdrop-blur-sm"
        ></div>

        <div
            x-show="drawerOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="relative w-full max-w-md bg-white border-l border-zinc-200 flex flex-col h-full shadow-2xl"
        >
            <div class="flex items-center justify-between px-8 py-6 border-b border-zinc-200">
                <div>
                    <p class="text-[0.65rem] tracking-[0.3em] uppercase text-zinc-400 mb-1">U N I T</p>
                    <p class="text-sm font-light tracking-wide text-zinc-900">Concierge</p>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        wire:click="clearChat"
                        class="text-xs font-light tracking-wider text-zinc-400 hover:text-zinc-700 transition-colors uppercase"
                        title="Clear conversation"
                    >
                        Clear
                    </button>
                    <button
                        @click="drawerOpen = false; $wire.closeChat()"
                        class="text-zinc-400 hover:text-zinc-700 transition-colors"
                        title="Close"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <div
                class="flex-1 overflow-y-auto px-8 py-6 space-y-6 scroll-smooth"
                x-ref="messages"
                x-effect="if (drawerOpen) setTimeout(() => { $el.scrollTop = $el.scrollHeight }, 350)"
                @chat-updated.window="$nextTick(() => { $refs.messages.scrollTop = $refs.messages.scrollHeight })"
            >
                @foreach($messages as $index => $message)
                    @if($message['role'] === 'assistant')
                        <div class="space-y-3">
                            <p class="text-[0.6rem] tracking-[0.25em] uppercase text-zinc-400">CONCIERGE</p>
                            <div class="bg-zinc-50 px-5 py-4">
                                <p class="text-sm font-light leading-relaxed text-zinc-700">
                                    {{ $message['content'] }}
                                </p>
                            </div>

                            @if(!empty($message['criteria']))
                                <button
                                    @click="drawerOpen = false"
                                    wire:click="viewResults({{ $index }})"
                                    wire:loading.attr="disabled"
                                    wire:target="viewResults"
                                    class="inline-flex items-center gap-2 px-4 py-2 text-xs font-light tracking-[0.15em] uppercase text-zinc-900 border border-zinc-200 hover:border-zinc-400 hover:bg-zinc-50 transition-colors disabled:opacity-50 disabled:cursor-wait"
                                >
                                    <span wire:loading.remove wire:target="viewResults({{ $index }})">
                                        View Curated Selection
                                    </span>
                                    <span wire:loading wire:target="viewResults({{ $index }})" class="flex items-center gap-2">
                                        <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                        Preparing...
                                    </span>
                                    <svg wire:loading.remove wire:target="viewResults({{ $index }})" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                    </svg>
                                </button>
                            @endif
                        </div>
                    @else
                        <div class="flex justify-end">
                            <div class="max-w-[85%] border border-zinc-200 px-5 py-4">
                                <p class="text-sm font-light leading-relaxed text-zinc-900">
                                    {{ $message['content'] }}
                                </p>
                            </div>
                        </div>
                    @endif
                @endforeach

                @if($thinking)
                    <div class="space-y-3">
                        <p class="text-[0.6rem] tracking-[0.25em] uppercase text-zinc-400">CONCIERGE</p>
                        <div class="bg-zinc-50 px-5 py-4">
                            <div class="flex items-center gap-2">
                                <div class="flex gap-1">
                                    <span class="w-1.5 h-1.5 bg-zinc-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
                                    <span class="w-1.5 h-1.5 bg-zinc-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
                                    <span class="w-1.5 h-1.5 bg-zinc-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
                                </div>
                                <span class="text-xs font-light text-zinc-400 tracking-wider">contemplating...</span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="border-t border-zinc-200 px-8 py-5 space-y-3">
                <form wire:submit="sendMessage" class="relative">
                    <input
                        type="text"
                        wire:model="input"
                        placeholder="Describe the space you desire..."
                        class="w-full bg-white border border-zinc-200 text-sm font-light text-zinc-900 placeholder:text-zinc-400 tracking-wide rounded-none focus:outline-none focus:border-zinc-400 pl-4 pr-12 py-3"
                        @disabled($thinking)
                        autofocus
                    />
                    <button
                        type="submit"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-900 transition-colors disabled:opacity-40"
                        @disabled($thinking)
                    >
                        @if($thinking)
                            <svg class="animate-spin h-5 w-5 text-zinc-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        @else
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                            </svg>
                        @endif
                    </button>
                </form>
                <p class="text-[0.6rem] tracking-wider text-zinc-400 font-light">
                    Powered by AI · Responses may vary
                </p>
            </div>
        </div>
    </div>
</div>
