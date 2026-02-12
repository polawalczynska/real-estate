<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Contracts\AiSearchInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class AiConciergeChat extends Component
{
    private const SESSION_MESSAGES = 'ai_chat_history';
    private const SESSION_OPEN     = 'ai_chat_open';

    public array $messages = [];

    public string $input = '';

    public bool $open = false;

    #[Locked]
    public bool $thinking = false;

    #[Locked]
    public bool $restoredFromSession = false;

    public function mount(): void
    {
        $sessionMessages = session()->get(self::SESSION_MESSAGES, []);
        $sessionOpen     = session()->get(self::SESSION_OPEN, false);

        if ($sessionMessages !== []) {
            $this->messages = $sessionMessages;
            $this->restoredFromSession = true;
        }

        $this->open = $sessionOpen;
    }

    public function openChat(): void
    {
        $this->open = true;

        if ($this->messages === []) {
            $this->messages[] = [
                'role'    => 'assistant',
                'content' => 'Welcome to U N I T. Describe the space you envision — the light, the neighbourhood, the feeling — and I will surface what resonates.',
            ];
        } elseif ($this->restoredFromSession) {
            $this->restoredFromSession = false;

            $cityContext = $this->extractLastCity();
            $suffix = $cityContext !== null
                ? "Did those spaces in {$cityContext} meet your expectations, or shall we refine the search?"
                : 'Shall we continue where we left off, or start fresh?';

            $this->messages[] = [
                'role'    => 'assistant',
                'content' => "Welcome back. {$suffix}",
            ];
        }

        $this->persistToSession();
        $this->dispatch('chat-updated');
    }

    public function closeChat(): void
    {
        $this->open = false;
        $this->persistToSession();
    }

    public function sendMessage(): void
    {
        $input = trim($this->input);

        if ($input === '') {
            return;
        }

        $this->messages[] = [
            'role'    => 'user',
            'content' => $input,
        ];

        $this->input    = '';
        $this->thinking = true;

        $this->persistToSession();
        $this->dispatch('chat-updated');

        // Re-render now so the user message + thinking indicator appear instantly,
        // then trigger the AI call as a separate round-trip.
        $this->js('$wire.processAiResponse()');
    }

    public function processAiResponse(): void
    {
        $lastUserMessage = $this->findLastUserMessage();

        if ($lastUserMessage === '') {
            $this->thinking = false;

            return;
        }

        try {
            $aiSearch = app(AiSearchInterface::class);

            $history = array_map(
                static fn (array $msg): array => [
                    'role'    => $msg['role'],
                    'content' => $msg['content'],
                ],
                array_slice($this->messages, -10, null, false),
            );

            $aiResponse  = $aiSearch->converse($lastUserMessage, $history);
            $criteria    = $aiResponse['criteria'];
            $queryParams = $criteria->toQueryParams();
            $hasFilters  = $criteria->hasActiveFilters();

            $content = $aiResponse['message'];
            if (! $hasFilters && $content === '') {
                $content = "I couldn't quite catch the specifics. Could you tell me more about the location or budget?";
            }

            $this->messages[] = [
                'role'     => 'assistant',
                'content'  => $content,
                'criteria' => $hasFilters ? $queryParams : null,
            ];

        } catch (Throwable $e) {
            Log::error('AiConciergeChat: Error', [
                'input' => $lastUserMessage,
                'error' => $e->getMessage(),
            ]);

            $this->messages[] = [
                'role'    => 'assistant',
                'content' => 'I apologise — I could not process that just now. Please try rephrasing your query.',
            ];
        } finally {
            $this->thinking = false;
            $this->persistToSession();
            $this->dispatch('chat-updated');
        }
    }

    private function findLastUserMessage(): string
    {
        foreach (array_reverse($this->messages) as $message) {
            if ($message['role'] === 'user') {
                return $message['content'];
            }
        }

        return '';
    }

    public function viewResults(int $messageIndex): void
    {
        $message = $this->messages[$messageIndex] ?? null;

        if ($message === null || empty($message['criteria'])) {
            return;
        }

        $this->open = false;
        $this->persistToSession();

        $this->redirect(route('listings.index', $message['criteria']), navigate: true);
    }

    public function clearChat(): void
    {
        $this->messages = [];
        $this->input    = '';
        $this->thinking = false;
        $this->restoredFromSession = false;

        session()->forget(self::SESSION_MESSAGES);

        $this->openChat();
    }

    private function persistToSession(): void
    {
        session()->put(self::SESSION_MESSAGES, $this->messages);
        session()->put(self::SESSION_OPEN, $this->open);
    }

    private function extractLastCity(): ?string
    {
        foreach (array_reverse($this->messages) as $message) {
            $city = $message['criteria']['city'] ?? null;
            if ($city !== null && $city !== '') {
                return $city;
            }
        }

        return null;
    }

    public function render(): View
    {
        return view('livewire.ai-concierge-chat');
    }
}
