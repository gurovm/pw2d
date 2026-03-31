# Architecture Roadmap: Single Database Multi-Tenancy

## Project Context
Upgrading a Laravel 11 / Livewire e-commerce comparison platform to a Multi-Tenant architecture.
**Core Strategy:** Single Database, Multiple Domains.
**Package:** `stancl/tenancy`
**Tenant Identification:** By Domain.

## The Goal
To run dozens of niche comparison websites (e.g., `best-mics.com`, `pro-keyboards.io`) from a single codebase and a single unified database, managed through one central admin panel, without compromising query performance or data isolation.

---

## Phase 1: Foundation (Installation & Configuration)
* Require `stancl/tenancy` via Composer.
* Initialize the package (`tenancy:install`).
* Configure `tenancy.php` strictly for **Single Database Tenancy** (disable multi-database creation, migrations, and seeders).
* Set up Domain identification middleware.

## Phase 2: The Data Layer (Schema & Indexes)
* **Tenant Table:** Utilize the package's default `tenants` and `domains` tables.
* **Core Tables:** Create a migration to add a `tenant_id` foreign key to all core entities (`products`, `categories`, etc.).
* **Composite Unique Constraints:** Drop global unique constraints (like `slug`) and replace them with scoped constraints: `unique(['tenant_id', 'slug'])`.
* **Performance Indexing:** To handle 500k+ rows efficiently, add composite indexes to heavily queried columns, always leading with the tenant: `index(['tenant_id', 'category_id'])`, `index(['tenant_id', 'match_score'])`.

## Phase 3: The Code Logic (Models & Routing)
* **Model Scoping:** Apply the `stancl/tenancy` Single DB trait (`use BelongsToTenant;`) to all tenant-specific Eloquent models to ensure automatic query scoping and saving.
* **Route Separation:** Move all front-end visitor routes from `web.php` to `tenant.php`. Apply the `InitializeTenancyByDomain` middleware to these routes.
* **Central Routes:** Keep admin panel and fallback routes in `web.php`.

## Phase 4: Local Simulation & Testing
* Configure local virtual hosts/domains (e.g., `mics.test`, `keyboards.test`).
* Create sample tenants and attach domains via tinker or the admin panel.
* Verify that Livewire components (like `GlobalSearch`) respect the `tenant_id` scope automatically.

## Phase 5: Production & DevOps
* Configure Nginx to accept wildcard domains or dynamically added server names.
* Automate SSL certificate generation (Certbot) for new tenant domains.
* Monitor database query execution plans to ensure composite indexes are being hit.

---
**AI Assistant Directives:**
1.  **Strict Scope:** Never suggest multi-database solutions. We are 100% committed to Single DB with `BelongsToTenant`.
2.  **Performance First:** Always include `tenant_id` as the first parameter in database indexes.
3.  **Livewire Compatibility:** Ensure any custom Livewire hydration/dehydration logic retains the active tenant context.
