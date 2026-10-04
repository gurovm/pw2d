<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SelectLandingPagePicks;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spec 041 Part 3 — one-time model backfill. Takes a `{product_id: model}` JSON
 * file (drafted in-session, one category at a time) and writes `products.model`.
 *
 * `--dry-run` writes nothing and prints (a) every group of 2+ products that
 * would count as the same model and (b) the page impact: the models are written
 * inside a transaction that is rolled back after `SelectLandingPagePicks` has
 * been re-run for the category and diffed against the stored picks.
 *
 * Writes go through `Product::update()` (never a mass update); the observer only
 * reacts to `is_ignored`/`category_id`, so no audit job fires.
 */
class ApplyProductModels extends Command
{
    protected $signature = 'pw2d:products:apply-models
                            {tenant : The tenant ID}
                            {file : Path to a JSON object of {product_id: model}}
                            {--dry-run : Write nothing; print same-model groups and the landing-page pick impact}';

    protected $description = 'Spec 041 — backfill products.model from a JSON file, showing same-model groups and page impact first';

    public function handle(): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if (!$tenant) {
            $this->error("Tenant not found: {$this->argument('tenant')}");
            return self::FAILURE;
        }

        $filePath = (string) $this->argument('file');

        if (!is_file($filePath)) {
            $this->error("File not found: {$filePath}");
            return self::FAILURE;
        }

        $models = json_decode((string) file_get_contents($filePath), true);

        if (!is_array($models) || $models === [] || array_is_list($models)) {
            $this->error('Invalid input file: expected a non-empty JSON object of {product_id: model}.');
            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            return $this->apply($tenant, $models, (bool) $this->option('dry-run'));
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @param array<int|string, mixed> $models
     */
    private function apply(Tenant $tenant, array $models, bool $dryRun): int
    {
        $errors = $this->validationErrors($tenant, $models);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->error('Nothing was written.');
            return self::FAILURE;
        }

        // Explicit tenant filter: Rule::exists would bypass the BelongsToTenant scope.
        $products = Product::where('tenant_id', $tenant->id)
            ->whereIn('id', array_keys($models))
            ->get()
            ->keyBy('id');

        $categoryIds = $products->pluck('category_id')->unique()->values();

        if ($dryRun) {
            $this->warn('DRY RUN — nothing was written.');
        }

        foreach ($categoryIds as $categoryId) {
            $category = Category::find($categoryId);
            $this->line('');
            $this->info("Category: {$category->name} (id {$category->id})");

            $this->renderGroups($category, $models);
            $this->renderPageImpact($tenant, $category, $models);
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->writeModels($tenant, array_keys($models), $models));
        $this->info(sprintf('Wrote model for %d product(s).', count($models)));

        return self::SUCCESS;
    }

    /**
     * @param array<int|string, mixed> $models
     * @return list<string>
     */
    private function validationErrors(Tenant $tenant, array $models): array
    {
        $errors = [];

        $found = Product::where('tenant_id', $tenant->id)
            ->whereIn('id', array_filter(array_keys($models), 'is_numeric'))
            ->get()
            ->keyBy('id');

        foreach ($models as $id => $model) {
            $product = is_numeric($id) ? $found->get((int) $id) : null;

            if ($product === null) {
                $errors[] = "Product {$id}: not found in tenant {$tenant->id}.";
            } elseif ($product->is_ignored || $product->status !== null || $product->category_id === null) {
                $errors[] = "Product {$id}: not live (ignored, unprocessed or detached).";
            }

            if (!is_string($model) || trim($model) === '' || mb_strlen(trim($model)) > 120) {
                $errors[] = "Product {$id}: model must be a non-empty string of at most 120 characters.";
            }
        }

        return $errors;
    }

    /**
     * Re-reads the rows on every call: after the dry-run's rolled-back write the
     * in-memory models would still hold the new value, and `update()` would see
     * nothing dirty and skip the real write.
     *
     * @param list<int|string> $ids
     * @param array<int|string, mixed> $models
     */
    private function writeModels(Tenant $tenant, array $ids, array $models): void
    {
        foreach (Product::where('tenant_id', $tenant->id)->whereIn('id', $ids)->get() as $product) {
            $product->update(['model' => trim($models[$product->id])]);
        }
    }

    /**
     * Groups of 2+ live products in the category that the pick guard will treat
     * as the same model once the file is applied (file value, else the stored one).
     *
     * @param array<int|string, mixed> $models
     */
    private function renderGroups(Category $category, array $models): void
    {
        $pool = Product::where('category_id', $category->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->with('brand:id,name')
            ->get();

        $groups = $pool
            ->map(function (Product $p) use ($models) {
                $model = isset($models[$p->id]) ? trim((string) $models[$p->id]) : (string) $p->model;

                return ['product' => $p, 'model' => $model, 'key' => $p->brand_id . ':' . self::normalize($model)];
            })
            ->filter(fn (array $row) => $row['product']->brand_id !== null && self::normalize($row['model']) !== '')
            ->groupBy('key')
            ->filter(fn (Collection $rows) => $rows->count() >= 2);

        if ($groups->isEmpty()) {
            $this->line('Same-model groups: none.');
            return;
        }

        $this->line(sprintf('Same-model groups: %d', $groups->count()));

        foreach ($groups as $rows) {
            $first = $rows->first();
            $this->line(sprintf('  %s / "%s" (%d)', $first['product']->brand?->name ?? '?', $first['model'], $rows->count()));

            foreach ($rows as $row) {
                $this->line(sprintf('      #%d %s', $row['product']->id, $row['product']->name));
            }
        }
    }

    /**
     * Writes the models inside a transaction, re-runs the picker, diffs against
     * the stored picks, and ALWAYS rolls back (the real write happens afterwards,
     * separately, when not a dry run).
     *
     * @param array<int|string, mixed> $models
     */
    private function renderPageImpact(Tenant $tenant, Category $category, array $models): void
    {
        $page = LandingPage::where('category_id', $category->id)->first();

        if ($page === null) {
            $this->line('Page impact: no landing page for this category.');
            return;
        }

        $stored = collect($page->picks ?? [])->pluck('role', 'product_id');

        DB::beginTransaction();

        try {
            $this->writeModels($tenant, array_keys($models), $models);

            try {
                $fresh = collect((new SelectLandingPagePicks())->execute($category))->pluck('role', 'product_id');
            } catch (\RuntimeException $e) {
                $this->warn('Page impact: the picker would fail after this change: ' . $e->getMessage());
                return;
            }
        } finally {
            DB::rollBack();
        }

        $names = Product::whereIn('id', $stored->keys()->merge($fresh->keys())->unique())->pluck('name', 'id');

        $removed = $stored->keys()->diff($fresh->keys());
        $added   = $fresh->keys()->diff($stored->keys());
        $changed = $stored->keys()->intersect($fresh->keys())->filter(fn ($id) => $stored[$id] !== $fresh[$id]);

        if ($removed->isEmpty() && $added->isEmpty() && $changed->isEmpty()) {
            $this->line('Page impact: picks unchanged.');
            return;
        }

        $this->line('Page impact: picks WOULD CHANGE');
        foreach ($removed as $id) {
            $this->line(sprintf('  - #%d %s (%s)', $id, $names[$id] ?? '?', $stored[$id]));
        }
        foreach ($added as $id) {
            $this->line(sprintf('  + #%d %s (%s)', $id, $names[$id] ?? '?', $fresh[$id]));
        }
        foreach ($changed as $id) {
            $this->line(sprintf('  ~ #%d %s: %s -> %s', $id, $names[$id] ?? '?', $stored[$id], $fresh[$id]));
        }
    }

    private static function normalize(string $model): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($model)) ?? '';
    }
}
