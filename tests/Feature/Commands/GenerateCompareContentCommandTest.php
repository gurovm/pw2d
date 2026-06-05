<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GenerateCompareContentCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var \App\Models\Tenant */
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'cmd-tenant', 'name' => 'Command Test Tenant']);
        $this->tenant = Tenant::find('cmd-tenant');
        // The command initializes tenancy itself, so we initialize here only
        // to use BelongsToTenant-scoped factories. We end it after seeding.
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
     * Standard AI fake: records calls and returns valid content.
     */
    private function makeAiSpy(): object
    {
        return new class extends AiService {
            public function __construct() {}

            public int $calls = 0;

            public function generateCompareContent(\App\Models\Category $category): array
            {
                $this->calls++;
                return [
                    'intro'       => '<p>Generated intro for ' . $category->name . '.</p>',
                    'methodology' => 'We rank by quality features.',
                    'faqs'        => [
                        ['question' => 'Q1?', 'answer' => 'A1.'],
                        ['question' => 'Q2?', 'answer' => 'A2.'],
                    ],
                ];
            }
        };
    }

    /**
     * AI fake that always throws.
     */
    private function makeAiThrower(): object
    {
        return new class extends AiService {
            public function __construct() {}

            public function generateCompareContent(\App\Models\Category $category): array
            {
                throw new \Exception('Simulated AI failure for ' . $category->slug);
            }
        };
    }

    /**
     * Create a leaf category (no children) with a feature so it's a valid compare page.
     */
    private function makeLeafCategory(string $slug, array $overrides = []): Category
    {
        $category = Category::factory()->create(array_merge([
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
        ], $overrides));

        Feature::factory()->create(['category_id' => $category->id]);

        return $category;
    }

    /**
     * All three buying_guide keys populated — marks the category as "already done".
     */
    private function fullyPopulatedGuide(): array
    {
        return [
            'intro'       => '<p>Existing intro.</p>',
            'methodology' => 'Existing methodology.',
            'faqs'        => [['question' => 'Existing Q?', 'answer' => 'Existing A.']],
        ];
    }

    // =========================================================================
    // Test 6: Skips categories with existing content unless --regenerate
    // =========================================================================

    /** @test */
    public function command_skips_categories_with_existing_content_unless_regenerate(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $this->makeLeafCategory('espresso-machines', [
            'buying_guide' => $this->fullyPopulatedGuide(),
        ]);

        // End tenancy so the command can re-initialize it
        tenancy()->end();

        Artisan::call('pw2d:generate-compare-content', [
            'tenant' => 'cmd-tenant',
        ]);

        $this->assertSame(0, $spy->calls, 'AI must not be called when all three keys exist and --regenerate is not set');
    }

    // =========================================================================
    // Test 7: --regenerate processes already-populated categories
    // =========================================================================

    /** @test */
    public function command_regenerate_processes_already_populated_categories(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $this->makeLeafCategory('espresso-regen', [
            'buying_guide' => $this->fullyPopulatedGuide(),
        ]);

        tenancy()->end();

        Artisan::call('pw2d:generate-compare-content', [
            'tenant'       => 'cmd-tenant',
            '--regenerate' => true,
        ]);

        $this->assertSame(1, $spy->calls, 'AI must be called once when --regenerate is set, even for already-populated category');
    }

    // =========================================================================
    // Test 8: --category filters to only the specified category
    // =========================================================================

    /** @test */
    public function command_processes_only_the_specified_category(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $target = $this->makeLeafCategory('podcast-mics');
        $other  = $this->makeLeafCategory('headphones');

        tenancy()->end();

        Artisan::call('pw2d:generate-compare-content', [
            'tenant'     => 'cmd-tenant',
            '--category' => 'podcast-mics',
        ]);

        // Only target category should have been touched
        $this->assertSame(1, $spy->calls, 'AI must be called exactly once for the specified --category');

        // Reload from DB to verify only target was updated
        tenancy()->initialize($this->tenant);
        $updatedTarget = Category::find($target->id);
        $updatedOther  = Category::find($other->id);

        $this->assertNotEmpty($updatedTarget->buying_guide['intro'] ?? null, 'Target category buying_guide.intro must be set');
        $this->assertNull($updatedOther->buying_guide['intro'] ?? null, 'Other category must not have been touched');
    }

    // =========================================================================
    // Test 9: --dry-run does not save to database
    // =========================================================================

    /** @test */
    public function command_dry_run_does_not_save(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $category = $this->makeLeafCategory('dry-run-cat', ['buying_guide' => null]);

        tenancy()->end();

        Artisan::call('pw2d:generate-compare-content', [
            'tenant'    => 'cmd-tenant',
            '--dry-run' => true,
        ]);

        // AI was called (content was generated) but nothing saved
        $this->assertSame(1, $spy->calls, 'AI must be called even in --dry-run mode');

        tenancy()->initialize($this->tenant);
        $fresh = Category::find($category->id);
        $this->assertNull(
            $fresh->buying_guide['intro'] ?? null,
            '--dry-run must not persist buying_guide.intro to the database'
        );
    }

    // =========================================================================
    // Test 10: Returns FAILURE (exit code 1) if any category errored
    // =========================================================================

    /** @test */
    public function command_returns_failure_if_any_category_errored(): void
    {
        $thrower = $this->makeAiThrower();
        app()->instance(AiService::class, $thrower);

        $this->makeLeafCategory('failing-cat');

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:generate-compare-content', [
            'tenant' => 'cmd-tenant',
        ]);

        $this->assertSame(\Illuminate\Console\Command::FAILURE, $exitCode, 'Command must return FAILURE (1) when any category errors');
    }

    // =========================================================================
    // Test 11: Returns SUCCESS (exit code 0) when all categories complete cleanly
    // =========================================================================

    /** @test */
    public function command_returns_success_when_all_categories_complete_cleanly(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $this->makeLeafCategory('success-cat');

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:generate-compare-content', [
            'tenant' => 'cmd-tenant',
        ]);

        $this->assertSame(\Illuminate\Console\Command::SUCCESS, $exitCode, 'Command must return SUCCESS (0) when all categories process cleanly');
    }

    // =========================================================================
    // Test 12: Only processes leaf categories (no children), skips parents
    // =========================================================================

    /** @test */
    public function command_only_processes_leaf_categories(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        // Parent category (has a child — should be skipped by doesntHave('children'))
        $parent = Category::factory()->create([
            'slug' => 'parent-cat',
            'name' => 'Parent Category',
        ]);
        Feature::factory()->create(['category_id' => $parent->id]);

        // Child (leaf) category under the parent
        $leaf = Category::factory()->create([
            'slug'      => 'leaf-cat',
            'name'      => 'Leaf Category',
            'parent_id' => $parent->id,
        ]);
        Feature::factory()->create(['category_id' => $leaf->id]);

        tenancy()->end();

        Artisan::call('pw2d:generate-compare-content', [
            'tenant' => 'cmd-tenant',
        ]);

        // Only the leaf should have been processed (not the parent)
        $this->assertSame(1, $spy->calls, 'AI must only be called for leaf categories, not parent categories');

        tenancy()->initialize($this->tenant);
        $updatedParent = Category::find($parent->id);
        $updatedLeaf   = Category::find($leaf->id);

        $this->assertNull(
            $updatedParent->buying_guide['intro'] ?? null,
            'Parent category (has children) must not be processed'
        );
        $this->assertNotEmpty(
            $updatedLeaf->buying_guide['intro'] ?? null,
            'Leaf category (no children) must be processed'
        );
    }

    // =========================================================================
    // Test 13: Command fails early when tenant does not exist
    // =========================================================================

    /** @test */
    public function command_returns_failure_when_tenant_not_found(): void
    {
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:generate-compare-content', [
            'tenant' => 'nonexistent-tenant',
        ]);

        $this->assertSame(\Illuminate\Console\Command::FAILURE, $exitCode, 'Command must return FAILURE when tenant is not found');
    }

    // =========================================================================
    // Test 14: Content is merged into existing buying_guide (not overwritten)
    // =========================================================================

    /** @test */
    public function command_merges_new_keys_into_existing_buying_guide(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        // Category with existing legacy keys only (no intro/methodology/faqs)
        $category = $this->makeLeafCategory('merge-test-cat', [
            'buying_guide' => [
                'how_to_decide' => '<p>Legacy buying guide content.</p>',
                'the_pitfalls'  => '<p>Legacy pitfalls.</p>',
            ],
        ]);

        tenancy()->end();

        Artisan::call('pw2d:generate-compare-content', [
            'tenant' => 'cmd-tenant',
        ]);

        tenancy()->initialize($this->tenant);
        $fresh = Category::find($category->id);

        // New keys should be present
        $this->assertNotEmpty($fresh->buying_guide['intro'] ?? null, 'intro must be merged in');
        $this->assertNotEmpty($fresh->buying_guide['methodology'] ?? null, 'methodology must be merged in');
        $this->assertNotEmpty($fresh->buying_guide['faqs'] ?? null, 'faqs must be merged in');

        // Legacy keys must be preserved
        $this->assertArrayHasKey('how_to_decide', $fresh->buying_guide, 'Legacy key how_to_decide must be preserved after merge');
        $this->assertArrayHasKey('the_pitfalls', $fresh->buying_guide, 'Legacy key the_pitfalls must be preserved after merge');
        $this->assertStringContainsString('Legacy buying guide content', $fresh->buying_guide['how_to_decide']);
    }
}
