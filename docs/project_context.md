# Project Context: Pw2D (Power to Decide)

**CRITICAL INSTRUCTION FOR ALL AGENTS:** Before designing, building, testing, or reviewing any feature, you MUST read and understand this document. It defines the business logic, architectural constraints, and technical stack of the Pw2D platform.

## 1. Business Model & Product Vision
Pw2D is an **AI-driven affiliate/recommendation platform** for the US market. It runs multiple niche comparison websites from a single codebase.
* **Core Feature — "Compare with Intelligence":** Users describe their needs in natural language (e.g., "best mic for noisy rooms under $200"). The AI identifies the right product category and dynamically ranks items using personalized priority sliders.
* **Revenue Model:** Multi-store affiliate commissions. Products link to the best-priced store with affiliate tags. Amazon Associates is the primary program; specialty stores (Clive Coffee, Whole Latte Love, etc.) are added via the `Store` model with per-store affiliate params.
* **The AI Bouncer Pipeline:** Products are scraped from Amazon (bulk SERP via Chrome Extension) and specialty stores (per-offer ingestion API), then run through an AI quality gate (Gemini API) that rejects accessories/junk, normalizes brand names, generates 2-sentence verdicts, and scores each product on category-specific features (0-100).
* **Dynamic Scoring:** Users adjust priority sliders (e.g., "Sound Quality: 90%, Price: 30%") and the product grid re-ranks instantly. No page reload — all computation happens server-side via Livewire computed properties.

## 2. Multi-Tenant Architecture
The platform uses **Single Database Multi-Tenancy** via `stancl/tenancy`.
* **Strategy:** One MySQL database, multiple domains. Each tenant (niche site) is identified by domain.
* **Package:** `stancl/tenancy` v3 with `BelongsToTenant` trait on all core models.
* **Tenant Identification:** `InitializeTenancyIfApplicable` middleware checks the request hostname against `config('tenancy.central_domains')`. Central domains skip tenancy; tenant domains resolve via `DomainTenantResolver` (cached, TTL 3600s).
* **Scoped Models:** `Category`, `Product`, `Brand`, `Feature`, `Preset`, `SearchLog`, `Setting`, `Store`, `ProductOffer`, `AiMatchingDecision` — all have `tenant_id` (string FK to `tenants.id`).
* **Central Domain:** `pw2d.com` — hosts the main site and the Filament admin panel.
* **Tenant Domains:** e.g., `coffee2decide.com`, `best-mics.com` — each shows only its own categories/products.
* **Admin Panel:** Filament v3 with native tenancy. A dropdown in the header switches between tenants. The `TenantSet` event bridges Filament's tenant context to stancl's `tenancy()->initialize()`.
* **Dynamic Branding:** Each tenant has custom colors (`primary_color`, `secondary_background_color`, `text_color`), logo, brand name, and hero copy — stored in the tenant's JSON `data` column via VirtualColumn trait. CSS variables (`--color-primary`, etc.) are injected in the layout `<head>` and mapped to Tailwind via `tenant.*` color tokens.
* **Routing:** Single set of named routes in `web.php`. The `InitializeTenancyIfApplicable` middleware (appended to the `web` group) handles tenant initialization transparently. No duplicate route declarations.
* **Rule:** NEVER suggest multi-database solutions. Single DB with `BelongsToTenant` only.

## 3. Core Data Model

### Entities
* **Tenant:** String primary key (e.g., `'pw2d'`, `'coffee-decide'`). Has many domains. Branding fields stored in JSON `data` column.
* **Category:** Tree structure (`parent_id` self-reference). Has `buying_guide` (JSON), `sample_prompts`, `image`. `category_id` is nullable on products (allows AI sweep to detach miscategorized items). Each leaf category defines its own `Feature` set, plus `budget_max` and `midrange_max` thresholds for price tiers.
* **Product:** The "Platonic Ideal" — one canonical record per real-world product. Belongs to `Brand` and `Category`. Has `ai_summary`, `price_tier` (1=Budget, 2=Mid, 3=Premium), Amazon rating/reviews, `is_ignored` (AI-detected accessories), `status` (null=processed, `pending_ai`, `failed`). Vendor-specific data (price, URL, ASIN) lives in `ProductOffer`, NOT on this table.
* **ProductOffer:** Multi-vendor aggregator — one record per store per product. Contains `url`, `scraped_price`, `raw_title`, `image_url`, `stock_status`. Links to `Product` and `Store`. Unique constraint: `(product_id, store_id)`.
* **Store:** Vendor/retailer entity. Has `name`, `slug` (unique per tenant), `affiliate_params` (query string appended to URLs, e.g., `tag=my-store-20`), `commission_rate`, `priority` (tiebreakers when prices equal), `is_active`.
* **Feature:** Category-specific scoring dimensions (e.g., "Sound Quality", "Build Quality"). `is_higher_better` drives normalization. Each product gets a `ProductFeatureValue` (score 0-100 + AI explanation).
* **Preset:** Named weight configurations per category (e.g., "For Podcasting"). Stores per-feature weights via `feature_preset` pivot.
* **Brand:** Created on-demand during AI processing. Scoped per tenant.
* **AiMatchingDecision:** Dedup cache — maps a scraped raw title to either an existing product (`is_match=true`) or "no match" (`is_match=false`). Indexed on `(tenant_id, scraped_raw_name)`. Prevents redundant AI calls for titles already evaluated.
* **AiCategoryRejection:** Records products flagged by AI as not belonging in a category. Unique on `(product_id, category_id)`. Used by the `AiSweepCategory` command.

### Key Relations
```
tenants ──< categories ──< features ──< product_feature_values >── products >── brands
                │                                                      │
                └──< presets >──< feature_preset >──< features         ├── category_id → categories
                                                                       ├──< product_offers >── stores
                                                                       ├──< ai_matching_decisions (dedup cache)
                                                                       └──< ai_category_rejections
```

### Product Accessors (computed from offers)
* `best_price` — Lowest `scraped_price` across all offers.
* `best_offer` — Determined by: lowest price → highest `commission_rate` → highest `priority`.
* `affiliate_url` — Built from `best_offer.url` + store's `affiliate_params`.
* `estimated_price` — Obfuscated for public display (rounded to nearest 5 or 10).
* `image_url` — Resolution chain: local `image_path` → best offer `image_url` → any offer `image_url`.

## 4. AI Architecture

### Two-Layer Stack

| Layer | Class | Responsibility |
|-------|-------|----------------|
| **Domain** | `app/Services/AiService.php` | Builds prompts, selects model, returns parsed data |
| **Transport** | `app/Services/GeminiService.php` | HTTP transport, auth headers, response parsing, error handling |

### AiService Methods

| Method | Model | Callers | Purpose |
|--------|-------|---------|---------|
| `evaluateProduct()` | admin_model | `ProcessPendingProduct` job | Full quality gate + brand normalization + feature scoring |
| `rescanFeatures()` | admin_model | `RescanProductFeatures` job | Lightweight re-scoring (skips quality gate) |
| `matchProduct()` | site_model | `ProcessPendingProduct`, `OfferIngestionService` | Dedup: does scraped title match an existing product? |
| `parseSearchQuery()` | site_model | `GlobalSearch` Livewire | Route user's natural language query to category + preset |
| `chatResponse()` | site_model | `ProductCompare` Livewire (AI Concierge) | Adjust feature weights from conversational input |
| `sweepCategoryPollution()` | site_model | `AiSweepCategory` command | Identify products that don't belong in a category |
| `extractProductFromText()` | admin_model | `ListProducts` Filament action | Parse raw text into structured product data |

### Environment Models (`config/services.php` → `.env`)
- `AGENT_SITE_MODEL` (default: `gemini-2.5-flash`) — fast, cheap, user-facing
- `AGENT_ADMIN_MODEL` (default: `gemini-2.5-pro`) — powerful, admin/scoring tasks
- `AGENT_IMAGE_MODEL` (default: `gemini-2.5-flash-image`) — category hero images

**STRICT RULE:** Never call GeminiService or the Gemini HTTP API directly from controllers, jobs, or Livewire components. All AI calls MUST go through `AiService`.

### AI Processing Pipeline (The AI Bouncer)
1. **Scrape & Send:** Chrome Extension scrapes Amazon SERP → `POST /api/products/batch-import`. Specialty stores → `POST /api/extension/ingest-offer`.
2. **Queue:** Import controllers create a `Product` stub (`status='pending_ai'`) + a `ProductOffer` (store, price, URL, image_url, raw_title) and dispatch `ProcessPendingProduct`.
3. **AI Bouncer (Quality Gate):** Worker calls `AiService::evaluateProduct()`. Gemini relies on **world knowledge** and strictly enforces:
   - **Rejection:** Kills accessories, bundles, generic/white-label products by marking `is_ignored=true`.
   - **Normalization:** Cleans and normalizes brand names (e.g., "RØDE" → "Rode").
4. **AI Memory Matching (Dedup):** After evaluation, calls `AiService::matchProduct()` to check if this product already exists under a different offer/ASIN. Uses 3-step flow: cache → heuristic → AI call. If match found, merges offers into the existing product and deletes the duplicate stub.
5. **Category Rejection Check:** Checks `ai_category_rejections` — if product was previously swept out of this category, detaches it (`category_id=null`).
6. **Scoring:** For valid non-duplicate products, Gemini returns the cleaned name, brand, an AI summary, and feature scores (0-100).
7. **Finalize:** Job creates/finds `Brand`, updates the `Product` record (sets `status=null`), saves `ProductFeatureValue` rows, downloads the hi-res image from the offer's `image_url`.

### AI Matching Flow (AiService::matchProduct)
1. **Cache check:** Exact match on `(tenant_id, scraped_raw_name)` in `ai_matching_decisions`.
2. **Heuristic:** If no fully-processed products exist for this brand → save `is_match=false`, return null.
3. **AI call:** Send product list for this brand to Gemini. Color variants count as matches.
4. **Save decision:** Cache result (both positive and negative) permanently.

## 5. Multi-Store Ingestion

### Import Paths

| Path | Controller | Use Case | Matching |
|------|-----------|----------|----------|
| Amazon SERP bulk | `BatchImportController` | Chrome Extension scrapes SERP page | Dedup by ASIN (existing offers check), then `ProcessPendingProduct` AI matching |
| Any store single offer | `OfferIngestionController` | Chrome Extension or external scraper | `OfferIngestionService` → AI matching (if brand provided) → `ProcessPendingProduct` |
| Single product import | `ProductImportController` | Legacy endpoint | Direct import, `ProcessPendingProduct` for AI |

### OfferIngestionService Flow
1. **Resolve store** — `Store::firstOrCreate()` from `store_slug`.
2. **URL dedup** — If `ProductOffer` exists for this URL+store → update price, return `refreshed`.
3. **AI matching** — If brand provided, call `AiService::matchProduct()`. If match → create offer on existing product, return `matched`.
4. **New product** — Create `Product` stub (`status='pending_ai'`) + `ProductOffer`, dispatch `ProcessPendingProduct`, return `created`.

### Store Model
- `affiliate_params` — Query string appended to product URLs (e.g., `tag=my-store-20`).
- `commission_rate` + `priority` — Tiebreakers when multiple stores have the same price.
- `is_active` — Inactive stores hidden from comparisons.
- Admin: Filament `StoreResource` under "Product Management" > "Stores / Vendors".

## 6. Chrome Extension Integration
* **Location:** `/chrome_extension` directory (Manifest V3).
* **Purpose:** Bulk-scrapes Amazon SERP pages and individual product pages. Extracts: ASIN, product title, price, rating, reviews count, hi-res image URLs.
* **Authentication:** `X-Extension-Token` header (token stored in `chrome.storage.local`, NOT hardcoded). `X-Tenant-Id` header for multi-tenancy.
* **Client-side filters:** Skips refurbished/renewed, packages, unavailable products, and suspiciously cheap items before sending to API.
* **API Dependencies:**
  1. `GET /api/categories` — list categories for dropdown
  2. `GET /api/existing-asins?category_id={id}` — prevent duplicate scraping (reads from `product_offers` URLs)
  3. `POST /api/products/batch-import` — bulk SERP import (creates Product stubs + ProductOffers)
  4. `POST /api/product-import` — single product import
  5. `POST /api/extension/ingest-offer` — multi-store offer ingestion (rate: 120/min)
* **Important Rule:** NEVER alter endpoint URLs without simultaneously updating `popup.js` and `content.js`.

## 7. Maintenance Commands

| Command | Purpose | AI Cost |
|---------|---------|---------|
| `pw2d:sync-offer-prices` | Re-scrape prices/stock for all active offers. Supports `--store`, `--limit`, `--dry-run`. Store-specific HTML parsing (Amazon patterns + generic fallback). | Zero |
| `pw2d:ai-sweep-category {slug}` | AI identifies products that don't belong in a category. Creates `AiCategoryRejection` records, sets `product.category_id=null`. Chunks of 25. Supports `--dry-run`. | Yes (site_model) |
| `products:recalculate-tiers` | Recalculates price tiers from best offer prices using category thresholds. | Zero |
| `pw2d:migrate-to-offers` | One-time migration of legacy `external_id`/`scraped_price` into `product_offers` + `ai_matching_decisions`. Already run. | Zero |

## 8. Technology Stack & Coding Standards
* **Backend:** Laravel 12 (PHP 8.3+). Strict types, thin controllers, Service classes for business logic.
* **Frontend:** Blade Templates + Livewire v3 + Alpine.js. Tailwind CSS compiled via Vite.
* **Database:** MySQL (production + local). Always use migrations with `up()` and `down()`.
* **Testing:** PHPUnit/Pest. Every feature must have tests. Run `php artisan test` before completing any task.
* **Cache:** File driver locally, Redis on production. 90s TTL on scored-products computations.
* **Queue:** Database driver. Supervisor manages 2 workers on production.
* **Image Optimization:** `ImageOptimizer` service converts to WebP (800px max width, quality 80) via `cwebp`. Auto-runs on AI-generated images and scraped product images.
* **SSRF Protection:** Image downloads restricted to allowlisted CDN hosts (`config('services.allowed_image_hosts')`) + auto-allowed store domains + `.shopify.com` / `.cloudfront.net`.

## 9. Environments & Infrastructure
* **Production Server:** 209.97.153.234 (DigitalOcean, Ubuntu 24.04 LTS)
  * Web Server: Nginx
  * Path: `/var/www/pw2d`
  * SSL: Certbot
  * PHP: 8.3-FPM
* **Deployment:** Strictly via `/deploy` command. Never auto-deploy after coding.
  * Sequence: `git pull` → `composer install --no-dev` → `migrate --force` → `npm run build` → `optimize:clear` → restart php-fpm

## 10. Key Livewire Components
* **`ProductCompare`:** The main category page. Computes scored products server-side, supports H2H comparison mode (session-persisted), focus-and-bump from search, SEO metadata injection.
* **`ComparisonHeader`:** Side panel with AI concierge, preset pills, and priority sliders. All dynamic colors via CSS variables.
* **`GlobalSearch`:** Instant DB search + explicit AI trigger. Context-aware boosting (products from the current category rank first). Used in both nav bar and hero variants.
* **`Home`:** Landing page with dynamic hero copy, search hints derived from categories, popular category grid.

## 11. Agent-Specific Directives
* **@architect:** Always respect single-DB multi-tenancy. Every new table needs `tenant_id`. Composite indexes must lead with `tenant_id`. Never break the tenant/central domain separation.
* **@builder:** Use `var(--color-primary)` / `bg-tenant-primary` for dynamic colors, never hardcode brand colors. Ensure `BelongsToTenant` is added to any new model. Mobile-first responsive design. Product price/URL data belongs in `ProductOffer`, never on the `Product` model.
* **@tester:** Tests run on central domain context (localhost). Use `Livewire::test()` for component tests. Factories must set `slug` explicitly for products. Use `RefreshDatabase` trait.
* **@reviewer:** Check for N+1 queries, missing tenant scoping, hardcoded colors, and explicit `tenant_id` in API controllers (safety net for non-tenancy-middleware routes). Verify that new product data goes to `ProductOffer`, not `Product`.
