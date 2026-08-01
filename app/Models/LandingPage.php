<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A "Best X" landing page (Spec 027) — one per leaf category.
 *
 * Picks are selected deterministically (see App\Actions\SelectLandingPagePicks)
 * and enriched with AI-written prose (see AiService::generateLandingPageContent).
 * `picks` shape: [{product_id, role, headline, body}], role ∈
 * overall/budget/premium/preset:{slug}.
 */
class LandingPage extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'category_id',
        'slug',
        'title_template',
        'intro',
        'picks',
        'faqs',
        'methodology_note',
        'status',
        'generated_at',
    ];

    protected $casts = [
        'picks'        => 'array',
        'faqs'         => 'array',
        'generated_at' => 'datetime',
    ];

    /**
     * Transient, non-persisted override for the picks count used by the `title`
     * accessor (Spec 027 review S2). A real declared property short-circuits
     * Eloquent's attribute magic, so setting this on an in-memory instance never
     * touches $attributes/dirty-tracking and is never saved to the database.
     *
     * LandingPageController::buildViewModel() sets this to the count of picks that
     * actually survive the deleted/detached/ignored render-time filter, BEFORE
     * building the view AND before calling SeoSchema::forLandingPage() with the
     * SAME $page instance — so the H1, <title>, and ItemList `name` always agree
     * with what's actually on the page, even when a stale pick silently drops out.
     */
    public ?int $renderedPickCount = null;

    protected static function booted(): void
    {
        $forgetCaches = function (LandingPage $page) {
            Cache::forget($page->cacheKey());
            Cache::forget(self::tenantScopedCacheKey($page->tenant_id, 'sitemap:xml'));
        };

        static::saved($forgetCaches);
        static::deleted($forgetCaches);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The cache key used by LandingPageController's tenant-scoped view-model cache.
     *
     * Deterministic: derived from the row's own `tenant_id` column, NOT the ambient
     * tenancy() context (Spec 027 review S1) — a `LandingPage::save()` from tinker,
     * a queued job, or any future code path that touches this model outside an
     * initialized tenancy still invalidates the correct tenant's cache. Same key
     * FORMAT as `tenant_cache_key()` (`t{tenant}:...`) so existing cached entries
     * built under initialized tenancy remain addressable.
     */
    public function cacheKey(): string
    {
        return self::tenantScopedCacheKey($this->tenant_id, "landing:{$this->slug}");
    }

    private static function tenantScopedCacheKey(?string $tenantId, string $key): string
    {
        return 't' . ($tenantId ?? 'central') . ":{$key}";
    }

    /**
     * Computed page title: explicit override, or the derived data-driven headline.
     * "The {N} Best {Category} in {YEAR} — Ranked by Data" (Spec 027 §3).
     *
     * N comes from `renderedPickCount` when the controller has set it (the accurate,
     * post-filter count); falls back to the stored `picks` JSON count for admin/list
     * contexts (e.g. Filament) where no render-time filtering has happened.
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!empty($this->title_template)) {
                    return $this->title_template;
                }

                $count        = $this->renderedPickCount ?? (is_array($this->picks) ? count($this->picks) : 0);
                $categoryName = $this->category?->name ?? '';
                $year         = date('Y');

                return "The {$count} Best {$categoryName} in {$year} — Ranked by Data";
            }
        );
    }
}
