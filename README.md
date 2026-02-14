### Data Extraction

Structured metadata (title, price, area, rooms, city, street, type, description, keywords) and AI-curated images (hero + gallery URLs) enable fast filtering, natural-language search, and cross-platform deduplication via semantic fingerprints.

### How Unstructured or Low-Quality Data Is Handled

**Phase 1: DOM extraction** (`HtmlExtractorService`): Parses JSON-LD `@type` nodes and `additionalProperty` fields from raw HTML. Extracts price, city, area, rooms, street, and image URLs deterministically - no AI involved. This provides the data needed for fingerprint calculation and reduces AI token usage.

**Phase 2: AI normalisation + imputation** (`AiNormalizationService`): Claude translates Polish content to English, maps building types to `PropertyType` enums, curates images (1 hero + 4 gallery via URL patterns), and repairs missing fields (rooms/type inferred from description context). A local safety net re-runs imputation after the AI call.

**Phase 3: Validation & scoring**: `ListingDTO` validates critical fields (price, area_m2, city). Missing criticals → status `INCOMPLETE` or `FAILED` (hidden from users). A quality score (0–100) tracks completeness: −20 for missing street, −10 for missing rooms/description/type, −5 for missing keywords/images.

### Where and Why AI Is Used

**1. Normalisation & Repair:** Translates Polish titles/descriptions to English, cleans street prefixes, maps types to `PropertyType` enums (apartment, house, loft, townhouse, studio, penthouse, villa), and imputes missing rooms/type from context.

**2. Image Curation:** Selects 5 images (1 hero + 4 gallery) from available photos using URL patterns and text labels (not visual analysis).

**3. Natural-Language Search:** The AI Concierge converts queries like *"sunny loft in Kraków under 2M"* into structured filters (city, type, keywords, price range) that drive the Eloquent query.

### One Key Assumption

Real estate portals embed JSON-LD structured data. The extractor relies on `@type` detection (Product, Apartment, Residence, etc.) and `additionalProperty` fields. Schema changes on the source portal require parser updates.

### One Success Metric

**Fingerprint-First Deduplication:** Pre-AI duplicate detection (30-day window) using JSON-LD metadata skips AI calls entirely, saving API costs. Post-AI fingerprint re-check catches cross-platform duplicates and merges them automatically.

### One Failure Mode or Limitation

**Queue Worker Dependency:** Requires workers on `ai` and `media` queues running simultaneously. If the AI worker stops, listings stay in `PENDING` status indefinitely. No automatic health checks or restarts are implemented.

### What Would Be Improved With More Time

1. **Multi-provider support** - currently only Otodom.pl
2. **Visual content filtering** - use image recognition to filter floor plans from hero selection etc.
3. **Incremental updates** - re-scrape existing listings for price/status changes
4. **Enhanced deduplication** - the app currently only recognizes exact duplicates

### Example User Journeys

**Journey 1: Natural-Language Search**
1. User types *"sunny loft in Kraków under 2M"* in the hero search bar
2. AI Concierge parses intent → `city: "Krakow"`, `type: "loft"`, `keywords: ["sunny"]`, `price_max: 2000000`
3. System displays filtered listings
4. User clicks a listing card → views full details with gallery

**Journey 2: Filter-Based Discovery**
1. User browses the listings page and applies filters: price range, area, rooms, city, property type
2. Eloquent scopes query indexed structured metadata
3. User clicks a listing card → views full details with gallery
