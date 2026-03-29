# Pw2D (Power to Decide) - AI Project Rules & Context

## 1. Project Overview
Pw2D is a **Multi-Vendor Price Aggregator & AI Comparison Platform** targeted at the US market. It scrapes products from multiple retailers (Amazon, specialty stores), deduplicates them via AI matching, and presents unified product pages with cross-store price comparison. Its core feature is "Compare with Intelligence" — an AI-driven search that takes natural language inputs from users (e.g., use case, budget, preferences), matches them to the right product category, and dynamically ranks items based on their specific needs.

## 2. Tech Stack & Architecture
- **Backend:** Laravel 11 (PHP 8.3)
- **Frontend:** Blade Templates, Tailwind CSS, compiled via Vite (Node.js v20)
- **Database:** MySQL
- **AI Integration:** Uses AI models to parse user prompts and rank database items.

## 3. Environments & Server Infrastructure
- **Local Environment:** Standard Laravel Valet/Serve development. Database is local.
- **Production Server:** - **IP:** 209.97.153.234 (DigitalOcean, Ubuntu 24.04 LTS)
  - **Web Server:** Nginx (`pw2d.com`, `www.pw2d.com`)
  - **Path:** `/var/www/pw2d`
  - **SSL:** Secured via Certbot.
- **Sub-system (TAU Chatbox):** There is a separate Python/FastAPI/Docker project running on the same server under `t.pw2d.com` (Port 8010). Do not confuse the Laravel monolithic codebase with the TAU Chatbox project.

### 4. STRICT Deployment Workflow (CRITICAL BOUNDARY)

🛑 **STOP! NEVER INITIATE DEPLOYMENT AUTOMATICALLY.** 🛑
Under NO circumstances should you execute deployment commands, SSH into the server, or run build scripts after finishing a coding task. Your job ends when the code is written and tested locally. Deployment is strictly handled via the `/deploy` command.

## 5. Coding Standards
- Write clean, modern PHP 8.3 (use strict types, match expressions, arrow functions).
- Frontend changes should utilize Tailwind utility classes; avoid writing custom CSS unless absolutely necessary.
- Measurements in recipes/specs should prioritize logical, standard metric/imperial units clearly. 
- Always review `routes/web.php` and Controller logic before proposing architecture changes.
## 6. Testing Requirements (TDD/Automated Tests)
- Every new feature, API endpoint, or core logic update MUST be accompanied by a corresponding test.
- Use Laravel's built-in testing tools (Pest or PHPUnit).
- Tests must cover the "happy path" as well as edge cases and error handling.
- Run `php artisan test` locally to verify functionality before completing a task.

## 7. Chrome Extension Integration
- **Location:** `/chrome_extension` directory (Manifest V3).
- **Purpose:** Bulk-scrapes Amazon SERP pages and individual product pages. Extracts: ASIN, product title, price, rating, reviews count, hi-res image URLs.
- **Authentication:** `X-Extension-Token` header (token stored in `chrome.storage.local`, NOT hardcoded). `X-Tenant-Id` header for multi-tenancy.
- **Client-side filters:** Skips refurbished/renewed, packages, unavailable products, and suspiciously cheap items before sending to API.
- **API Dependencies:**
  1. `GET /api/categories` — list categories for dropdown
  2. `GET /api/existing-asins?category_id={id}` — prevent duplicate scraping (reads from `product_offers` URLs)
  3. `POST /api/products/batch-import` — bulk SERP import (creates Product stubs + ProductOffers)
  4. `POST /api/product-import` — single product import
- **Payload Structure:** `{ "asin": "...", "title": "...", "price": 54.99, "rating": 4.5, "reviews_count": 300, "image_url": "https://...", "status": "pending_ai" }`
- **Important Rule:** NEVER alter endpoint URLs without simultaneously updating `popup.js` and `content.js`.

## 8. Database Structure & Relations

### Core Tables

**`categories`**
- `id`, `parent_id` (nullable → self, tree structure), `name`, `slug` (unique), `description`
- `image` (storage path, nullable)
- `buying_guide` (JSON: `{how_to_decide, the_pitfalls, key_jargon}`, nullable)
- Self-referential: a category can have a parent category (subcategory support).

**`brands`**
- `id`, `name`, `logo_path` (nullable)
- Created on-demand by `ProcessPendingProduct` via `Brand::firstOrCreate()`.

**`products`** *(The "Platonic Ideal" — one canonical record per real-world product)*
- `id`, `brand_id` → `brands` (nullable FK), `category_id` → `categories`, `name` (VARCHAR 255)
- `slug` (unique per product), `ai_summary` (text, AI-generated 2-sentence verdict)
- `price_tier` (int: 1=Budget, 2=Mid-range, 3=Premium — thresholds set per category via `budget_max`/`midrange_max`)
- `amazon_rating` (float 0–5, nullable), `amazon_reviews_count` (int)
- `image_path` (locally stored WebP image, `storage/products/images/`)
- `affiliate_url` (nullable — if null, auto-generated from best offer URL + affiliate tag)
- `is_ignored` (boolean, default false — AI-detected accessories suppressed from all site surfaces)
- `status` (nullable string: `null`=fully processed, `pending_ai`=queued for AI, `failed`=AI exhausted retries)
- **Key accessors:** `best_price` (lowest offer price), `best_offer` (cheapest offer record), `estimated_price` (obfuscated for display)
- Vendor-specific data (price, URL, ASIN, raw title) lives in `product_offers`, NOT on this table.

**`features`**
- `id`, `category_id` → `categories`, `name`, `unit` (e.g. "dB", "grams", nullable)
- `is_higher_better` (boolean — drives normalization direction)
- `min_value`, `max_value` (floats, nullable — used by `ProductScoringService` to normalize 0–100)
- Each category defines its own scoring dimensions here.

**`product_feature_values`**
- `id`, `product_id` → `products`, `feature_id` → `features`
- `raw_value` (float, 0–100 AI score), `explanation` (text, AI one-sentence reason, nullable)
- Unique: `(product_id, feature_id)` — one value per feature per product.

**`presets`**
- `id`, `category_id` → `categories`, `name`
- Named weight configurations (e.g. "For Podcasting", "For Gaming").

**`feature_preset`** (pivot)
- `preset_id` → `presets`, `feature_id` → `features`, `weight` (int 0–100, default 50)
- Stores per-feature weight for each preset.

**`search_logs`**
- `id`, `type` (`global_search` | `homepage_ai` | `category_ai`), `query` (text)
- `category_name` (nullable string), `user_id` → `users` (nullable)
- `results` (JSON, matched product IDs/names), `summary` (text, AI explanation of ranking)

**`product_offers`** *(Multi-Vendor Aggregator — store-specific data)*
- `id`, `product_id` → `products` (cascade), `tenant_id` → `tenants` (cascade)
- `store_name` (string 100, e.g. 'Amazon', 'Clive Coffee')
- `url` (text, full product URL at this store — for Amazon: `amazon.com/dp/{ASIN}`)
- `scraped_price` (decimal 10,2, nullable), `raw_title` (string 500, exact scraped title)
- `image_url` (text, nullable — external CDN image URL from this store)
- `stock_status` (string 50, nullable — 'in_stock', 'out_of_stock')
- Unique: `(product_id, store_name)` — one offer per store per product.
- **This is where all vendor-specific data lives.** Products table holds only canonical/AI-processed data.

**`ai_matching_decisions`** *(AI Memory Layer)*
- `id`, `tenant_id` → `tenants` (cascade)
- `scraped_raw_name` (string 500, the raw title from store)
- `existing_product_id` → `products` (nullable, nullOnDelete)
- `is_match` (boolean)
- Index: `(tenant_id, scraped_raw_name)` — fast lookup before calling AI.
- Purpose: caches AI decisions on whether a scraped title matches an existing product, preventing duplicate API calls.

**`settings`**
- `key` (unique), `value` (text) — key/value store for site-wide config (e.g. AI model overrides).

> **Legacy fields DROPPED (Phase 2 complete):** `products.scraped_price`, `products.external_id`, `products.external_image_path` have been migrated to `product_offers` and removed from the products table.

### Laravel System Tables
- `users` — admin/staff accounts (Filament admin panel access)
- `jobs` — queue jobs (QUEUE_CONNECTION=database). Queue workers run via Supervisor (2 processes, `www-data` user).
- `failed_jobs` — exhausted queue retries land here (normally empty).
- `cache` — Redis is the cache driver (90s TTL on scored-products computations).

### Key Relations (summary)

```
categories ──< features ──< product_feature_values >── products >── brands
     │                                                   │   │
     └──< presets >──< feature_preset >──< features      │   └── category_id → categories
                                                         │
                                                         └──< product_offers (store-specific prices/URLs)
                                                         └──< ai_matching_decisions (dedup cache)
```

- `Category` has many `Feature`, `Product`, `Preset`
- `Product` belongs to `Brand` and `Category`; has many `ProductFeatureValue`, `ProductOffer`
- `ProductOffer` belongs to `Product` — one per store per product (Amazon, Clive Coffee, etc.)
- `Feature` belongs to `Category`; pivots to `Preset` via `feature_preset`
- `ProductFeatureValue` links one `Product` to one `Feature` with a score + explanation
- `Preset` belongs to `Category`; carries per-feature weights for the comparison sliders
- `AiMatchingDecision` caches AI dedup results — prevents re-asking Gemini for known title→product mappings

### AI Architecture (Single Responsibility)

All AI interactions go through a **two-layer stack**:

| Layer | Class | Responsibility |
|-------|-------|----------------|
| **Domain** | `app/Services/AiService.php` | Builds prompts, selects model, returns parsed data |
| **Transport** | `app/Services/GeminiService.php` | HTTP transport, auth headers, response parsing, error handling |

**AiService methods** (prompt templates live here — callers pass only data):

| Method | Model | Callers |
|--------|-------|---------|
| `evaluateProduct()` | admin_model | `ProcessPendingProduct` job |
| `rescanFeatures()` | admin_model | `RescanProductFeatures` job |
| `matchProduct()` | site_model | `ProcessPendingProduct` job (dedup after evaluation) |
| `parseSearchQuery()` | site_model | `GlobalSearch` Livewire component |
| `chatResponse()` | site_model | `ProductCompare` Livewire component (AI Concierge) |
| `extractProductFromText()` | admin_model | `ListProducts` Filament action |

**Environment models** (`config/services.php` → `.env`):
- `AGENT_SITE_MODEL` (default: `gemini-2.5-flash`) — fast, cheap, user-facing
- `AGENT_ADMIN_MODEL` (default: `gemini-2.5-pro`) — powerful, admin/scoring tasks
- `AGENT_IMAGE_MODEL` (default: `gemini-2.5-flash-image`) — category hero images

**STRICT RULE:** Never call GeminiService or the Gemini HTTP API directly from controllers, jobs, or Livewire components. All AI calls MUST go through `AiService` (for domain methods) or `GeminiService` (for admin-only raw prompts like category content generation in `EditCategory`).

### AI Processing Pipeline (The AI Bouncer)
1. **Scrape & Send:** Chrome Extension scrapes Amazon SERP → POST `/api/products/batch-import`.
2. **Queue:** `BatchImportController` creates a `Product` stub (status=`pending_ai`) + a `ProductOffer` (store=Amazon, price, URL, image_url, raw_title) and dispatches `ProcessPendingProduct`.
3. **AI Bouncer (Quality Gate):** Worker calls `AiService::evaluateProduct()` with the product title + best offer price. Gemini relies on **world knowledge** and strictly enforces:
   - **Rejection:** Kills accessories, bundles, generic/white-label products by marking `is_ignored=true`.
   - **Normalization:** Cleans and normalizes brand names (e.g., "RØDE" → "Rode").
4. **AI Memory Matching (Dedup):** After evaluation, calls `AiService::matchProduct()` to check if this product already exists under a different offer/ASIN. Uses 3-step flow: cache → heuristic → AI call. If match found, merges offers into the existing product and deletes the duplicate stub.
5. **Scoring:** For valid non-duplicate products, Gemini returns the cleaned name, brand, an AI summary, and feature scores (0–100).
6. **Finalize:** Job creates/finds `Brand`, updates the `Product` record (sets `status=null`), saves `ProductFeatureValue` rows, downloads the hi-res image from the offer's `image_url`.

### Maintenance Commands
- `pw2d:sync-offer-prices` — Re-scrapes prices/stock for all active offers. **Zero AI cost** — HTML parsing only. Supports `--store`, `--limit`, `--dry-run`.
- `pw2d:migrate-to-offers` — One-time migration of legacy data into `product_offers` + `ai_matching_decisions`.
- `products:recalculate-tiers` — Recalculates price tiers from best offer prices using category thresholds.

## Agent Team

This project uses a multi-agent system. Each agent has a defined role:

| Agent | Trigger | Responsibility |
|-------|---------|----------------|
| **architect** | "plan", "design", "architect" | Designs features, writes specs to `docs/specs/` |
| **builder** | "build", "implement", "code" | Implements specs, writes actual Laravel code |
| **reviewer** | "review", "check", "quality" | Reviews code quality and Laravel conventions |
| **tester** | "write tests", "test coverage" | Writes PHPUnit/Pest tests |
| **security** | "security check", "audit" | Audits for vulnerabilities |
| **documenter** | "document", "API docs" | Writes docs, PHPDoc, README |
| **performance** | "performance", "optimize", "slow", "N+1", "cache" | Audits for bottlenecks, N+1 queries, caching gaps |
| **frontend** | "blade", "view", "UI", "component", "tailwind", "page", "form", "alpine" | Builds Blade templates, Tailwind styling, Alpine.js interactivity |

## AI Agent Workflow & Behavioral Rules
To ensure high-quality output and maintain context across long development sessions, you MUST adhere to the following behavioral standards:

* **Plan First (Task Management):** For any non-trivial task (3+ steps), you must first write a detailed, checkable plan to a `docs/todo.md` file. Do not start writing application code until the user approves the plan. Mark items complete as you go.
* **The Self-Improvement Loop:** We maintain a `docs/lessons.md` file. After ANY correction from the user regarding architecture, syntax, or business logic, you MUST update this file with a new rule to prevent the same mistake. You must review `docs/lessons.md` at the start of new tasks.
* **Verification Before Done:** Never mark a task complete without proving it works. You must run the relevant tests, check logs, and demonstrate correctness. Ask yourself: "Would a Staff Engineer approve this?" before presenting it.
* **Autonomous Bug Fixing (No Laziness):** When given a bug report, error log, or failing CI test: just fix it. Find the root cause and resolve it. Do not ask for hand-holding or permission to write the fix. 
* **Demand Elegance (Simplicity First):** Make every change as simple as possible. Impact minimal code. For complex changes, pause and ask yourself if there is a more elegant, Laravel-native solution before over-engineering. No temporary fixes; adhere to senior developer standards.
