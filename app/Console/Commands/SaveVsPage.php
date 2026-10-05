<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\VsPage;
use App\Support\ListingHealth;
use App\Support\ModelIdentity;
use App\Support\ProseStyle;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Spec 043 — saves a Claude-written, owner-reviewed VS page, behind four guards:
 * selection (same tenant/category, both live and pick-eligible, different models),
 * price (current estimated_price within 10% of the draft's snapshot), and style
 * (ProseStyle::violations() empty). Any failed guard refuses and writes nothing.
 *
 * File shape: {"product_a_id","product_b_id","intro","sections":{"a_wins","b_wins",
 * "who_should_buy"},"verdict","faqs":[{"q","a"}],"price_snapshot":{"<id>":int,"<id>":int}}.
 * The pair is re-ordered so product_a is the alphabetically first comparison name (brand + model, else name); a_wins/b_wins
 * swap with the ids.
 */
class SaveVsPage extends Command
{
    private const PRICE_GUARD = 0.10;

    protected $signature = 'pw2d:vs-pages:save
                            {tenant : The tenant ID}
                            {file : Path to the JSON draft}
                            {--publish : Publish the page (otherwise a new page is a draft; an existing page keeps its status)}';

    protected $description = 'Validate and save a head-to-head VS page from a JSON draft (Spec 043)';

    public function handle(): int
    {
        $tenant = Tenant::find($this->argument('tenant'));

        if ($tenant === null) {
            $this->error('Tenant not found: ' . $this->argument('tenant'));
            return self::FAILURE;
        }

        $file = (string) $this->argument('file');

        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (($problem = $this->structureProblem($data)) !== null) {
            $this->error("Refused: {$problem}");
            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            return $this->save($tenant, $data);
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(Tenant $tenant, array $data): int
    {
        // BelongsToTenant scopes this lookup: an id from another tenant simply isn't found.
        $products = Product::whereIn('id', [$data['product_a_id'], $data['product_b_id']])
            ->with(['brand:id,name', 'offers.store'])
            ->get()
            ->keyBy('id');

        $errors = $this->guardErrors($data, $products);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error("Refused: {$error}");
            }
            $this->line('Nothing was written.');

            return self::FAILURE;
        }

        // Alphabetical by name; the sections and snapshots follow the products.
        $first  = $products->get($data['product_a_id']);
        $second = $products->get($data['product_b_id']);
        $sections = $data['sections'];

        if (strcasecmp($first->comparisonName(), $second->comparisonName()) > 0) {
            [$first, $second] = [$second, $first];
            [$sections['a_wins'], $sections['b_wins']] = [$sections['b_wins'], $sections['a_wins']];
        }

        $slug = Str::slug($first->comparisonName()) . '-vs-' . Str::slug($second->comparisonName());

        // The pair in either id order is one page (names can change, ids cannot swap by accident).
        $existing = VsPage::where(fn ($q) => $q
                ->where(fn ($p) => $p->where('product_a_id', $first->id)->where('product_b_id', $second->id))
                ->orWhere(fn ($p) => $p->where('product_a_id', $second->id)->where('product_b_id', $first->id)))
            ->first();

        $clash = VsPage::where('slug', $slug)->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists();

        if ($clash) {
            $this->error("Refused: slug \"{$slug}\" is already used by a different page.");
            $this->line('Nothing was written.');

            return self::FAILURE;
        }

        $attributes = [
            'category_id'      => $first->category_id,
            'product_a_id'     => $first->id,
            'product_b_id'     => $second->id,
            'title'            => "{$first->comparisonName()} vs {$second->comparisonName()}: scores, price and which to buy",
            'intro'            => $data['intro'],
            'verdict'          => $data['verdict'],
            'sections'         => $sections,
            'faqs'             => $data['faqs'],
            'price_snapshot_a' => $first->estimated_price,
            'price_snapshot_b' => $second->estimated_price,
            'generated_at'     => now(),
        ];

        if ($existing !== null) {
            // Slug and status are kept (the URL accrues links); only --publish changes status.
            $existing->fill($attributes + ($this->option('publish') ? ['status' => 'published'] : []))->save();
            $page = $existing;
            $verb = 'Updated';
        } else {
            $page = VsPage::create($attributes + [
                'tenant_id' => $tenant->getTenantKey(),
                'slug'      => $slug,
                'status'    => $this->option('publish') ? 'published' : 'draft',
            ]);
            $verb = 'Created';
        }

        $this->info("{$verb} /vs/{$page->slug} ({$page->status}).");

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function guardErrors(array $data, $products): array
    {
        $errors = [];
        $a      = $products->get($data['product_a_id']);
        $b      = $products->get($data['product_b_id']);

        foreach (['product_a_id' => $a, 'product_b_id' => $b] as $key => $product) {
            if ($product === null) {
                $errors[] = "{$key} {$data[$key]} not found in this tenant.";
            }
        }

        if ($a === null || $b === null) {
            return $errors;
        }

        if ($a->id === $b->id) {
            return ['the two products are the same product.'];
        }

        if ($a->category_id === null || $a->category_id !== $b->category_id) {
            $errors[] = 'the products are not in the same category.';
        }

        foreach ([$a, $b] as $product) {
            if ($product->is_ignored || $product->status !== null || $product->category_id === null) {
                $errors[] = "\"{$product->name}\" is not live (hidden, pending or category-less).";
            }

            if (!$product->offers->contains(fn ($o) => ListingHealth::isPickEligible($o))) {
                $errors[] = "\"{$product->name}\" has no pick-eligible offer.";
            }
        }

        if (ModelIdentity::sameModel($a, $b)) {
            $errors[] = 'the products are the same model (colour or variant copies).';
        }

        foreach ([$a, $b] as $product) {
            $snapshot = $data['price_snapshot'][(string) $product->id] ?? null;
            $current  = $product->estimated_price;

            if (!is_numeric($snapshot) || $snapshot <= 0 || $current === null) {
                $errors[] = "\"{$product->name}\" needs a price snapshot in the file and a current estimated price.";
            } elseif (abs($current - $snapshot) / $snapshot > self::PRICE_GUARD) {
                $errors[] = sprintf(
                    '"%s" price moved from $%s (draft) to $%s (now), more than %d%%; re-check the prose.',
                    $product->name,
                    $snapshot,
                    $current,
                    self::PRICE_GUARD * 100,
                );
            }
        }

        $prose = [
            'intro'                    => $data['intro'],
            'sections.a_wins'          => $data['sections']['a_wins'],
            'sections.b_wins'          => $data['sections']['b_wins'],
            'sections.who_should_buy'  => $data['sections']['who_should_buy'],
            'verdict'                  => $data['verdict'],
        ];

        foreach ($data['faqs'] as $i => $faq) {
            $prose["faqs.{$i}.q"] = $faq['q'];
            $prose["faqs.{$i}.a"] = $faq['a'];
        }

        foreach ($prose as $field => $html) {
            $hits = ProseStyle::violations($html);

            if ($hits !== []) {
                $errors[] = "banned phrase(s) in {$field}: " . implode(', ', $hits) . '.';
            }
        }

        return $errors;
    }

    /**
     * @return string|null what is wrong with the file, or null when it is well-formed
     */
    private function structureProblem(mixed $data): ?string
    {
        if (!is_array($data)) {
            return 'the file is not valid JSON.';
        }

        foreach (['product_a_id', 'product_b_id'] as $key) {
            if (!isset($data[$key]) || !is_int($data[$key])) {
                return "\"{$key}\" must be an integer id.";
            }
        }

        foreach (['intro', 'verdict'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
                return "\"{$key}\" must be a non-empty string.";
            }
        }

        foreach (['a_wins', 'b_wins', 'who_should_buy'] as $key) {
            $value = $data['sections'][$key] ?? null;

            if (!is_string($value) || trim($value) === '') {
                return "\"sections.{$key}\" must be a non-empty string.";
            }
        }

        if (!isset($data['faqs']) || !is_array($data['faqs']) || $data['faqs'] === []) {
            return '"faqs" must be a non-empty list of {q, a}.';
        }

        foreach ($data['faqs'] as $faq) {
            if (!is_array($faq) || !is_string($faq['q'] ?? null) || !is_string($faq['a'] ?? null) || $faq['q'] === '' || $faq['a'] === '') {
                return 'every FAQ needs a non-empty "q" and "a".';
            }
        }

        if (!isset($data['price_snapshot']) || !is_array($data['price_snapshot'])) {
            return '"price_snapshot" must map each product id to its price.';
        }

        return null;
    }
}
