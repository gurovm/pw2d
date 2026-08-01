<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\Feature;
use App\Models\LandingPage;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Spec 027 §9.6 — pw2d:generate-landing-page command tests.
 *
 * Tests run on central domain (localhost). Tenancy is initialized manually for
 * seeding, then ended before Artisan::call so the command initializes tenancy
 * itself — matches GeneratePresetContentCommandTest's established pattern.
 */
class GenerateLandingPageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'lp-cmd-tenant', 'name' => 'LP Command Tenant']);
        $this->tenant = Tenant::find('lp-cmd-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * AiService spy: records calls and returns a valid generateLandingPageContent()
     * payload built from the picks it was actually given (echoes product ids back).
     */
    private function makeAiSpy(string $introText = 'Generated intro'): object
    {
        return new class ($introText) extends AiService {
            public function __construct(private string $introText) {}

            public int $calls = 0;
            /** @var array<int, string> */
            public array $lastExcludeFaqQuestions = [];

            public int $lastScoredProductCount = 0;

            public function generateLandingPageContent(\App\Models\Category $category, array $picks, array $excludeFaqQuestions, int $scoredProductCount = 0): array
            {
                $this->calls++;
                $this->lastExcludeFaqQuestions = $excludeFaqQuestions;
                $this->lastScoredProductCount  = $scoredProductCount;

                return [
                    'intro' => '<p>' . $this->introText . ' for ' . $category->name . '.</p>',
                    'picks' => collect($picks)->map(fn (array $p) => [
                        'product_id' => $p['product_id'],
                        'headline'   => 'Headline for ' . $p['product']->name,
                        'body'       => '<p>Body for ' . $p['product']->name . '.</p>',
                    ])->all(),
                    'faqs' => [
                        ['question' => 'Generated FAQ question?', 'answer' => 'Generated answer.'],
                    ],
                    'methodology_note' => 'Generated methodology note.',
                ];
            }
        };
    }

    /**
     * A leaf category with a single feature and $count fully-eligible products
     * (enough to satisfy SelectLandingPagePicks' MIN_PICKS of 5 by default).
     */
    private function makeLeafCategoryWithEligibleProducts(string $slug, int $count = 5, array $categoryOverrides = []): Category
    {
        $category = Category::factory()->create(array_merge([
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
        ], $categoryOverrides));

        $feature = Feature::factory()->create(['category_id' => $category->id, 'is_higher_better' => true, 'unit' => null]);

        foreach (range(1, $count) as $i) {
            $product = Product::factory()->create([
                'category_id'   => $category->id,
                'slug'          => $slug . '-product-' . $i,
                'ai_summary'    => 'Summary ' . $i,
                'is_ignored'    => false,
                'status'        => null,
                'amazon_rating' => null,
                'price_tier'    => 2,
            ]);

            ProductOffer::create([
                'product_id' => $product->id,
                'url'        => "https://example.com/{$slug}-{$i}",
                'raw_title'  => $product->name,
                'image_url'  => "https://images.example.com/{$slug}-{$i}.jpg",
            ]);

            ProductFeatureValue::factory()->create([
                'product_id' => $product->id,
                'feature_id' => $feature->id,
                'raw_value'  => 50 + $i,
            ]);
        }

        return $category;
    }

    // =========================================================================
    // --dry-run makes ZERO AI calls
    // =========================================================================

    /** @test */
    public function dry_run_makes_zero_ai_calls_and_persists_nothing(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $category = $this->makeLeafCategoryWithEligibleProducts('lp-cmd-dry-run');

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:generate-landing-page', [
            'tenant'        => 'lp-cmd-tenant',
            'category-slug' => 'lp-cmd-dry-run',
            '--dry-run'     => true,
        ]);

        $this->assertSame(0, $spy->calls, '--dry-run must make ZERO AI calls');
        $this->assertSame(Command::SUCCESS, $exitCode);

        tenancy()->initialize($this->tenant);
        $this->assertNull(LandingPage::where('category_id', $category->id)->first(), '--dry-run must not persist a landing page');
    }

    // =========================================================================
    // Full run: persists draft with AI-generated picks + faqs
    // =========================================================================

    /** @test */
    public function full_run_persists_a_draft_landing_page_with_ai_generated_content(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $category = $this->makeLeafCategoryWithEligibleProducts('lp-cmd-full-run');

        tenancy()->end();

        Artisan::call('pw2d:generate-landing-page', [
            'tenant'        => 'lp-cmd-tenant',
            'category-slug' => 'lp-cmd-full-run',
        ]);

        $this->assertSame(1, $spy->calls);

        tenancy()->initialize($this->tenant);
        $page = LandingPage::where('category_id', $category->id)->first();

        $this->assertNotNull($page);
        $this->assertSame('draft', $page->status, 'New pages must default to draft without --publish');
        $this->assertCount(5, $page->picks);
        $this->assertArrayHasKey('headline', $page->picks[0]);
        $this->assertArrayHasKey('body', $page->picks[0]);
        $this->assertNotEmpty($page->faqs);
        $this->assertStringContainsString('Generated intro', $page->intro);
        $this->assertNotNull($page->generated_at);
    }

    // =========================================================================
    // --publish sets status published
    // =========================================================================

    /** @test */
    public function publish_flag_sets_status_to_published(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $this->makeLeafCategoryWithEligibleProducts('lp-cmd-publish');

        tenancy()->end();

        Artisan::call('pw2d:generate-landing-page', [
            'tenant'        => 'lp-cmd-tenant',
            'category-slug' => 'lp-cmd-publish',
            '--publish'     => true,
        ]);

        tenancy()->initialize($this->tenant);
        $page = LandingPage::where('slug', 'lp-cmd-publish')->first();

        $this->assertNotNull($page);
        $this->assertSame('published', $page->status);
    }

    // =========================================================================
    // Re-run without --regenerate skips; with --regenerate rewrites
    // =========================================================================

    /** @test */
    public function rerun_without_regenerate_skips_an_existing_landing_page(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $this->makeLeafCategoryWithEligibleProducts('lp-cmd-skip-rerun');

        tenancy()->end();

        Artisan::call('pw2d:generate-landing-page', [
            'tenant'        => 'lp-cmd-tenant',
            'category-slug' => 'lp-cmd-skip-rerun',
        ]);
        $this->assertSame(1, $spy->calls);

        Artisan::call('pw2d:generate-landing-page', [
            'tenant'        => 'lp-cmd-tenant',
            'category-slug' => 'lp-cmd-skip-rerun',
        ]);

        $this->assertSame(1, $spy->calls, 'A second run without --regenerate must not call the AI again');
    }

    /** @test */
    public function regenerate_flag_rewrites_the_existing_landing_pages_content(): void
    {
        $this->makeLeafCategoryWithEligibleProducts('lp-cmd-regenerate');

        tenancy()->end();

        // NOTE: a single AiService spy instance is bound ONCE and reused across both
        // Artisan::call() invocations below (varying its return value by call count),
        // rather than rebinding app()->instance(AiService::class, ...) between calls.
        // Laravel's console Application caches the resolved Command object the first
        // time Artisan::call() runs it within a test (ContainerCommandLoader::get()
        // resolves once, then Symfony's Application::has() short-circuits on the
        // cached instance) — a later app()->instance() rebind would never reach the
        // already-constructed Command's constructor-injected AiService property.
        $spy = new class extends AiService {
            public function __construct() {}

            public int $calls = 0;

            public function generateLandingPageContent(\App\Models\Category $category, array $picks, array $excludeFaqQuestions, int $scoredProductCount = 0): array
            {
                $this->calls++;
                $introText = $this->calls === 1 ? 'Original intro text' : 'Regenerated intro text';

                return [
                    'intro' => "<p>{$introText}</p>",
                    'picks' => collect($picks)->map(fn (array $p) => [
                        'product_id' => $p['product_id'],
                        'headline'   => 'Headline for ' . $p['product']->name,
                        'body'       => '<p>Body.</p>',
                    ])->all(),
                    'faqs'              => [['question' => 'Q?', 'answer' => 'A.']],
                    'methodology_note'  => 'note',
                ];
            }
        };
        app()->instance(AiService::class, $spy);

        Artisan::call('pw2d:generate-landing-page', [
            'tenant'        => 'lp-cmd-tenant',
            'category-slug' => 'lp-cmd-regenerate',
        ]);

        tenancy()->initialize($this->tenant);
        $original = LandingPage::where('slug', 'lp-cmd-regenerate')->first();
        $this->assertStringContainsString('Original intro text', $original->intro);
        $originalId = $original->id;
        tenancy()->end();

        Artisan::call('pw2d:generate-landing-page', [
            'tenant'        => 'lp-cmd-tenant',
            'category-slug' => 'lp-cmd-regenerate',
            '--regenerate'  => true,
        ]);

        $this->assertSame(2, $spy->calls, '--regenerate must trigger a second AI call');

        tenancy()->initialize($this->tenant);
        $regenerated = LandingPage::where('slug', 'lp-cmd-regenerate')->first();

        $this->assertSame($originalId, $regenerated->id, 'Regenerate must update the same row, not create a duplicate');
        $this->assertStringContainsString('Regenerated intro text', $regenerated->intro);
        $this->assertStringNotContainsString('Original intro text', $regenerated->intro);
    }

    // =========================================================================
    // FAQ exclusion list
    // =========================================================================

    /** @test */
    public function faq_exclusion_list_passed_to_ai_includes_category_and_preset_faqs(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $category = $this->makeLeafCategoryWithEligibleProducts('lp-cmd-faq-exclusion', 5, [
            'buying_guide' => [
                'faqs' => [['question' => 'Existing category FAQ?', 'answer' => 'A.']],
            ],
        ]);

        $preset = Preset::factory()->create([
            'category_id' => $category->id,
            'name'        => 'Test Preset',
            'seo_content' => [
                'intro' => '<p>x</p>',
                'faqs'  => [['question' => 'Existing preset FAQ?', 'answer' => 'A.']],
            ],
        ]);

        tenancy()->end();

        Artisan::call('pw2d:generate-landing-page', [
            'tenant'        => 'lp-cmd-tenant',
            'category-slug' => 'lp-cmd-faq-exclusion',
        ]);

        $this->assertContains('Existing category FAQ?', $spy->lastExcludeFaqQuestions);
        $this->assertContains('Existing preset FAQ?', $spy->lastExcludeFaqQuestions);
    }
}
