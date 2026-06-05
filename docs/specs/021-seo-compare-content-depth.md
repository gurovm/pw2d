# Spec 021: Compare Page Content Depth

**Status:** Draft (architect handoff)
**Authors:** @architect (2026-06-05)
**Depends on:** Spec 015 (SeoSchema), Spec 018/020 (titles + breadcrumb)
**Branch:** `feat/seo-compare-content-021`

---

## 1. Motivation

After Specs 018–020 cleaned up product/category schema, the root SEO problem remains: **0 clicks on 276 impressions in 28 days**. Schema fixes can't fix that on their own. The deeper diagnosis from the F29 investigation:

- Most unindexed products aren't duplicates — they're genuinely-different long-tail products that Google deemed not valuable enough to index because the site has near-zero domain authority and pages have thin content.
- Compare pages have ~3 paragraphs of buying-guide text (`how_to_decide` / `the_pitfalls` / `key_jargon`) and a product grid. That's it. Competitors (Wirecutter, RTINGS) have 2000+ word buying guides.
- Users see a thin SERP snippet, compare it against Amazon's listing or a known review site, and don't click.

This spec adds content depth using AI generation. The goal is to give compare pages enough substance that Google considers them authority-grade for the category, and users have enough context that the click feels worthwhile.

## 2. Goals

1. Extend `category.buying_guide` JSON with three new keys: `intro`, `faqs`, `methodology`
2. AI-generate this content via a new `AiService::generateCompareContent()` method + a `pw2d:generate-compare-content` artisan command
3. Render the new content on compare pages: `intro` above the existing tabs, `faqs` below the product grid, `methodology` in a "How We Rank" section
4. Emit a `FAQPage` schema entry when `faqs` is present (rich result eligibility for FAQs in SERPs)
5. Filament `CategoryResource` exposes the new fields so admins can review/edit AI output

## 3. Non-goals

- Image generation per FAQ
- Per-product Q&A
- Multi-language content
- Comparing to historical content versions (no versioning)
- Auto-regeneration on category changes (admin runs the command manually)

---

## 4. Data layer

### 4.1 buying_guide JSON shape (extended)

Current keys (preserved): `how_to_decide`, `the_pitfalls`, `key_jargon` — all strings, may contain HTML.

New keys:
```json
{
  "intro": "<p>Short 100-150 word hook that introduces the category, who it's for, and why our picks matter. Plain prose, no HTML beyond <p>.</p>",
  "methodology": "<p>2-3 sentence explanation of how the AI ranks products in this category. Mentions the top features. Builds trust.</p>",
  "faqs": [
    {"question": "What's the best ... for a beginner?", "answer": "..."},
    {"question": "How much should I spend on ...?", "answer": "..."},
    {"question": "Are ... worth the upgrade from ...?", "answer": "..."}
  ]
}
```

No migration needed — `buying_guide` is already cast as `array` on the Category model.

### 4.2 AI generation

#### `AiService::generateCompareContent(Category $category): array`

Returns the new shape:
```php
[
    'intro'       => 'string (HTML allowed for <p> tags only)',
    'methodology' => 'string',
    'faqs'        => [
        ['question' => '...', 'answer' => '...'],
        // 4-6 FAQs
    ],
]
```

Uses `admin_model` (gemini-2.5-pro) — content quality matters more than latency. Builds a prompt that includes:
- Category name + slug + parent category if any
- Up to 10 top features (from `category->features`)
- The category's existing `buying_guide['how_to_decide']` for context
- Up to 5 top-ranked products' names + AI summaries for category awareness

Prompt instructs the model to return JSON with the exact keys above. Validates the response shape (each FAQ has `question` and `answer` strings, etc.) before returning.

Error handling: throws on API failure or schema mismatch. The caller (command) decides whether to retry or skip.

#### `pw2d:generate-compare-content` command

Signature:
```
php artisan pw2d:generate-compare-content {tenant}
                                          {--category= : Slug of single category to process}
                                          {--regenerate : Overwrite existing content (default: skip categories that already have intro+faqs+methodology)}
                                          {--dry-run : Show what would be generated without saving}
```

Behavior:
1. Tenant arg is required (no implicit-all-tenants safety).
2. Initialize tenancy.
3. Build the list of leaf categories to process (filter by `--category` if set).
4. For each category:
   - Skip if `--regenerate` is false AND all three keys exist in `buying_guide`.
   - Call `AiService::generateCompareContent($category)`.
   - If `--dry-run`, print the generated content. Don't save.
   - Else, merge the new keys into `category.buying_guide` and save.
5. Print a summary: N processed, N skipped, N errored.

Default behavior is conservative: skip categories already populated. Admin uses `--regenerate` for refresh.

## 5. View layer

### 5.1 `intro` paragraph

In `resources/views/livewire/product-compare.blade.php`, above the existing buying-guide tabs block (~line 92). Only render if `$category->buying_guide['intro']` is present.

Markup:
```blade
@if (!empty($category->buying_guide['intro']))
    <div class="prose prose-sm max-w-none mb-4 text-gray-700 leading-relaxed">
        {!! $category->buying_guide['intro'] !!}
    </div>
@endif
```

Sanitization: AI-generated content is admin-reviewable; `{!!  !!}` is acceptable since the source is the AI (constrained to `<p>` tags by prompt) and the admin gates it. Future hardening could use `Str::sanitizeHtml()` or HTMLPurifier — out of scope here.

### 5.2 `faqs` section

Below the product grid. New Blade partial: `resources/views/livewire/partials/compare-faqs.blade.php`.

Renders an accordion (Alpine.js). Each item: question (button toggling) + answer (expanded panel). Default closed.

Only render when `$category->buying_guide['faqs']` is a non-empty array.

### 5.3 `methodology` block

Render below the slider panel (inside `ComparisonHeader` or in `ProductCompare`, pick whichever fits the existing structure). Small "How We Rank" callout. Plain prose.

Only render when present.

## 6. Schema layer

### 6.1 FAQPage schema

In `app/Support/SeoSchema.php::forLeafCategory`, append a third schema entry when `buying_guide['faqs']` exists:

```php
if (is_array($category->buying_guide['faqs'] ?? null) && !empty($category->buying_guide['faqs'])) {
    $faqSchema = [
        '@context' => 'https://schema.org/',
        '@type'    => 'FAQPage',
        'mainEntity' => array_map(
            fn (array $faq) => [
                '@type'          => 'Question',
                'name'           => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $faq['answer'],
                ],
            ],
            $category->buying_guide['faqs'],
        ),
    ];
    $schemas[] = $faqSchema;
}
```

`schemas` array now contains: `[ItemList, BreadcrumbList, FAQPage]` (when all three are present).

### 6.2 Why FAQPage matters

- Google can display FAQs as expandable rich result in SERPs
- Adds vertical real estate to the result, pushing competitors below the fold
- Increases CTR on rich-result-eligible pages by 10-30% per Google's case studies (their own published numbers, not my estimates)

## 7. Filament integration

`app/Filament/Resources/CategoryResource.php` already has fields for the buying_guide sub-keys (per Spec 010's EditCategory AI work). Add three new fields under the same "Buying Guide" section:

- `intro` — Textarea, hint: "Short 100-150 word hook. Renders above the tabs."
- `methodology` — Textarea, hint: "How the AI ranks. Renders as 'How We Rank' block."
- `faqs` — Repeater with `question` (TextInput) + `answer` (Textarea) fields. Hint: "4-6 FAQs. Renders below the product grid + emits FAQPage schema."

These tie into the existing buying_guide JSON dotted-path approach in EditCategory. The exact Filament syntax mirrors how `how_to_decide` etc. are currently wired — check that file first.

## 8. File-level summary

| File | Action |
|---|---|
| `app/Services/AiService.php` | MODIFY (add `generateCompareContent`) |
| `app/Console/Commands/GenerateCompareContent.php` | **CREATE** |
| `app/Support/SeoSchema.php` | MODIFY (append FAQPage schema in `forLeafCategory`) |
| `app/Filament/Resources/CategoryResource.php` | MODIFY (add 3 new form fields) |
| `resources/views/livewire/product-compare.blade.php` | MODIFY (render intro + methodology + include faqs partial) |
| `resources/views/livewire/partials/compare-faqs.blade.php` | **CREATE** |
| `tests/Feature/Seo/SeoSchemaTest.php` | MODIFY (FAQPage tests) |
| `tests/Feature/Ai/GenerateCompareContentTest.php` | **CREATE** |
| `docs/seo/operations.md` | UPDATE (how to run the content generation command) |
| `docs/tasks/todo.md` | UPDATE (mark F32 if relevant; this isn't F32 but adjacent) |

## 9. Tests

### AI generation
- `generateCompareContent returns the expected array shape` — mock GeminiService to return a JSON string, assert parsed structure.
- `generateCompareContent throws on malformed JSON response`.
- `generateCompareContent throws on missing required keys`.
- `generateCompareContent FAQs validates question + answer shape` — assert each FAQ has both keys.

### Command
- `command skips categories with existing content unless --regenerate` — seed a category with all three keys filled, run command, assert no AI call was made.
- `command processes only the specified --category` — seed two categories, run with `--category=foo`, assert only foo was touched.
- `--dry-run does not save` — seed empty category, run with `--dry-run`, assert buying_guide unchanged.
- `command returns FAILURE if any category errored`.

### Schema (FAQPage)
- `forLeafCategory emits FAQPage as third schemas entry when faqs is non-empty`.
- `forLeafCategory omits FAQPage when faqs is empty or missing`.
- `FAQPage mainEntity has correct Question/Answer structure`.

### View rendering (lightweight)
- `compare page renders intro paragraph when buying_guide.intro is set` — Livewire test or HTTP test against the route.
- `compare page omits intro section when not set`.
- `compare page renders FAQ accordion when faqs is set`.

## 10. Acceptance

- [ ] `php artisan test --filter='Seo|Ai|Compare'` — all green
- [ ] `php artisan pw2d:generate-compare-content pw2d --category=podcast-studio-mics --dry-run` — prints valid JSON locally
- [ ] After deploy: run on prod for at least one category. Verify intro/faqs/methodology render on `/compare/<slug>`. Verify FAQPage schema appears in HTML.
- [ ] Google Rich Results Test reports FAQPage as valid for at least one category URL.
- [ ] Wait 1 week, check Search Console → Enhancements → FAQ for valid items.

## 11. Rollout

1. PR `feat(seo): compare-page content depth (spec 021)`
2. Optional audit pass (reviewer)
3. Merge
4. `/deploy`
5. **Ops step (post-deploy):** SSH to prod, run `pw2d:generate-compare-content pw2d --dry-run` to see what AI generates. Inspect outputs. If quality is good, re-run without `--dry-run` to save. If quality is mixed, run per-category and review individually via Filament before saving.
6. Wait 1-2 weeks for Google to recrawl. Re-baseline GSC and FAQ rich-result coverage.

## 12. Cost estimate

- 5 active categories on prod × 1 generation each = 5 admin_model (gemini-2.5-pro) calls
- ~3000 input tokens (prompt + context) + ~2000 output tokens per call
- Per gemini-2.5-pro pricing: ~$0.01-0.05 per category
- Total: ~$0.05-0.25 for the initial population

Re-generation is opt-in via `--regenerate`. Trivial cost.
