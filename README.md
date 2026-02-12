# U N I T

**Intelligent real estate, distilled.**

A curated property platform combining automated data acquisition with AI-driven normalisation and natural-language search. Built on Laravel 12, Livewire 3, and Tailwind CSS 4.

---

## Technical Highlights

### Asynchronous Data Pipeline

Raw HTML from property portals flows through a multi-stage pipeline:

```
Scrape → DOM Metadata Extraction → Fingerprint Dedup → Claude AI Normalisation → Image Curation
```

Each listing is processed across two dedicated queues (`ai` for normalisation, `media` for image downloads), enabling the UI to display skeleton cards immediately while background jobs enrich data in parallel. Livewire's `wire:poll` drives automatic frontend refresh as jobs complete — images fade in without page reloads.

### Semantic Fingerprint Deduplication

A **fingerprint-first** strategy prevents duplicate AI spending. Before any API call, a normalised MD5 hash is computed from raw DOM metadata (city + street + price rounded to nearest 1,000 + area rounded to integer + rooms). Duplicates within a 30-day temporal window are merged instantly. A secondary post-AI fingerprint check catches cross-platform duplicates that raw extraction missed.

### AI Concierge (Natural-Language Search)

Users describe spaces in plain language — *"sunny loft in Kraków under 2M"* — and Claude parses intent into structured `SearchCriteriaDTO` filters. Conversation history (last 10 messages) provides multi-turn context. State persists in the Laravel session across `wire:navigate` page transitions.

### Real-Time Reactive UI

Reactive `wire:key` bindings on media containers force DOM updates when images arrive. Scoped `wire:target` directives prevent unrelated UI elements from flickering during background polls. Loading states use skeleton loaders with `animate-pulse` — never empty space.

---

## Architecture

| Layer | Technology |
|---|---|
| **Framework** | Laravel 12 (PHP 8.2+, `declare(strict_types=1)` everywhere) |
| **Frontend** | Livewire 3 · Alpine.js · Tailwind CSS 4 · Flux UI |
| **AI** | Anthropic Claude (Haiku for normalisation, Sonnet for search) |
| **Database** | MySQL 8+ with composite indexes, fulltext search, JSON columns |
| **Media** | Spatie Media Library (WebP conversions, hero designation) |
| **Queue** | Laravel Database Queue (parallel `ai` + `media` workers) |

### Design Patterns

- **Contract-driven services** — all AI, image, and scraping logic bound via interfaces (`AiNormalizerInterface`, `AiSearchInterface`, `ImageAttacherInterface`, `ListingProviderInterface`), making providers swappable without touching consumers.
- **Readonly DTOs** — `ListingDTO` and `SearchCriteriaDTO` enforce immutability for data flowing between services.
- **Enums for domain constants** — `ListingStatus` and `PropertyType` eliminate magic strings.
- **Centralised AI prompts** — `config/ai_prompts.php` keeps prompt engineering separate from business logic.
- **Fingerprint-first deduplication** — semantic hashing before AI calls minimises API spend.

## Setup

```bash
git clone <repo-url> unit && cd unit
composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate

# Configure .env: DB credentials + ANTHROPIC_API_KEY

php artisan migrate
php artisan storage:link
php artisan listings:import --limit=20

# Development (serves app, queue worker, Vite, and Pail in parallel)
composer run dev
```

## Environment Variables

| Key | Purpose |
|---|---|
| `ANTHROPIC_API_KEY` | Claude API key (required for AI pipeline) |
| `ANTHROPIC_MODEL` | Normalisation model (default: `claude-haiku-4-5-20251001`) |
| `ANTHROPIC_SEARCH_MODEL` | Search/concierge model (default: `claude-sonnet-4-5-20250929`) |
| `DB_CONNECTION` | `mysql` (only supported driver) |
| `QUEUE_CONNECTION` | `database` (required for async processing) |

## Key Commands

```bash
php artisan listings:import --limit=20        # Scrape + queue AI normalisation + image download
php artisan listings:import --limit=5 --sync  # Process synchronously (debugging)
php artisan listings:fix-images               # Re-attach missing images for existing listings
php artisan listings:backfill-fingerprints    # Generate dedup hashes for legacy records
php artisan listings:backfill-keywords        # Extract keywords heuristically (no AI cost)
composer run dev                              # Full dev stack (server + queue + Vite + Pail)
```
