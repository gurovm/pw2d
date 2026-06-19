<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\Feature;
use App\Models\FeaturePreset;
use App\Models\Preset;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 023 — pw2d:generate-preset-content command tests.
 *
 * Tests run on central domain (localhost). Tenancy is initialized manually
 * for seeding, then ended before Artisan::call so the command initializes
 * tenancy itself — matching the GenerateCompareContentCommandTest pattern.
 */
class GeneratePresetContentCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'preset-cmd-tenant', 'name' => 'Preset Command Test Tenant']);
        $this->tenant = Tenant::find('preset-cmd-tenant');
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
     * AiService spy: records calls and returns valid preset content.
     */
    private function makeAiSpy(): object
    {
        return new class extends AiService {
            public function __construct() {}

            public int $calls = 0;
            /** @var list<string> */
            public array $calledPresetNames = [];

            public function generatePresetContent(\App\Models\Preset $preset): array
            {
                $this->calls++;
                $this->calledPresetNames[] = $preset->name;

                return [
                    'intro' => '<p>Generated intro for preset: ' . $preset->name . '.</p>',
                    'faqs'  => [
                        ['question' => 'Q1 for ' . $preset->name . '?', 'answer' => 'A1.'],
                        ['question' => 'Q2 for ' . $preset->name . '?', 'answer' => 'A2.'],
                    ],
                ];
            }
        };
    }

    /**
     * AiService spy that throws on every call.
     */
    private function makeAiThrower(): object
    {
        return new class extends AiService {
            public function __construct() {}

            public function generatePresetContent(\App\Models\Preset $preset): array
            {
                throw new \Exception('Simulated AI failure for preset: ' . $preset->name);
            }
        };
    }

    /**
     * AiService that throws only for a specific preset name.
     */
    private function makeSelectiveThrower(string $failPresetName): object
    {
        return new class ($failPresetName) extends AiService {
            public function __construct(private string $failName) {}

            public int $calls = 0;

            public function generatePresetContent(\App\Models\Preset $preset): array
            {
                $this->calls++;
                if ($preset->name === $this->failName) {
                    throw new \Exception('Simulated failure for: ' . $preset->name);
                }
                return [
                    'intro' => '<p>Intro.</p>',
                    'faqs'  => [['question' => 'Q?', 'answer' => 'A.']],
                ];
            }
        };
    }

    /**
     * Create a leaf category with one feature and one preset attached.
     * Returns the preset (with category relation set).
     */
    private function makeLeafWithPreset(string $categorySlug, string $presetName): Preset
    {
        $category = Category::factory()->create([
            'slug' => $categorySlug,
            'name' => ucfirst(str_replace('-', ' ', $categorySlug)),
        ]);

        $feature = Feature::factory()->create(['category_id' => $category->id]);

        $preset = Preset::factory()->create([
            'category_id' => $category->id,
            'name'        => $presetName,
        ]);

        // Attach feature to preset via pivot.
        FeaturePreset::create(['preset_id' => $preset->id, 'feature_id' => $feature->id, 'weight' => 80]);

        return $preset;
    }

    // =========================================================================
    // Test 1: Happy path — writes seo_content to preset
    // =========================================================================

    /** @test */
    public function command_writes_seo_content_to_matching_presets(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $preset = $this->makeLeafWithPreset('gaming-keyboards', 'Streamer');

        tenancy()->end();

        Artisan::call('pw2d:generate-preset-content', [
            'tenant' => 'preset-cmd-tenant',
        ]);

        $this->assertSame(1, $spy->calls);

        tenancy()->initialize($this->tenant);
        $fresh = Preset::find($preset->id);

        $this->assertNotNull($fresh->seo_content, 'seo_content must be saved to the preset');
        $this->assertIsArray($fresh->seo_content);
        $this->assertArrayHasKey('intro', $fresh->seo_content);
        $this->assertArrayHasKey('faqs', $fresh->seo_content);
        $this->assertStringContainsString('Streamer', $fresh->seo_content['intro']);
    }

    // =========================================================================
    // Test 2: --dry-run does not save to database
    // =========================================================================

    /** @test */
    public function command_dry_run_does_not_save(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $preset = $this->makeLeafWithPreset('dry-run-keyboards', 'Minimalist');

        tenancy()->end();

        Artisan::call('pw2d:generate-preset-content', [
            'tenant'    => 'preset-cmd-tenant',
            '--dry-run' => true,
        ]);

        // AI was called, but nothing persisted.
        $this->assertSame(1, $spy->calls, 'AI must be called even in --dry-run mode');

        tenancy()->initialize($this->tenant);
        $fresh = Preset::find($preset->id);
        $this->assertNull($fresh->seo_content, '--dry-run must NOT save seo_content to the database');
    }

    // =========================================================================
    // Test 3: --category filter scopes to one category only
    // =========================================================================

    /** @test */
    public function command_category_filter_scopes_to_specified_category(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $targetPreset = $this->makeLeafWithPreset('gaming-headsets', 'Remote Worker');
        $otherPreset  = $this->makeLeafWithPreset('gaming-mice', 'FPS Player');

        tenancy()->end();

        Artisan::call('pw2d:generate-preset-content', [
            'tenant'     => 'preset-cmd-tenant',
            '--category' => 'gaming-headsets',
        ]);

        $this->assertSame(1, $spy->calls, 'Only the target category preset must be processed');
        $this->assertSame(['Remote Worker'], $spy->calledPresetNames);

        tenancy()->initialize($this->tenant);
        $freshTarget = Preset::find($targetPreset->id);
        $freshOther  = Preset::find($otherPreset->id);

        $this->assertNotNull($freshTarget->seo_content, 'Target preset must have seo_content saved');
        $this->assertNull($freshOther->seo_content, 'Other category preset must not be touched');
    }

    // =========================================================================
    // Test 4: --preset filter scopes by slug (Str::slug(name))
    // =========================================================================

    /** @test */
    public function command_preset_filter_scopes_by_slug(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        // Two presets in the same category — only one should be processed.
        $category = Category::factory()->create([
            'slug' => 'streaming-keyboards-slug-test',
            'name' => 'Streaming Keyboards',
        ]);

        $feature = Feature::factory()->create(['category_id' => $category->id]);

        $presetA = Preset::factory()->create(['category_id' => $category->id, 'name' => 'Streamer']);
        $presetB = Preset::factory()->create(['category_id' => $category->id, 'name' => 'Home Office']);

        FeaturePreset::create(['preset_id' => $presetA->id, 'feature_id' => $feature->id, 'weight' => 80]);
        FeaturePreset::create(['preset_id' => $presetB->id, 'feature_id' => $feature->id, 'weight' => 60]);

        tenancy()->end();

        // Filter by slug of 'Streamer' which is 'streamer'.
        Artisan::call('pw2d:generate-preset-content', [
            'tenant'   => 'preset-cmd-tenant',
            '--preset' => Str::slug('Streamer'),  // 'streamer'
        ]);

        $this->assertSame(1, $spy->calls, 'Only the streamer preset must be processed');
        $this->assertSame(['Streamer'], $spy->calledPresetNames);

        tenancy()->initialize($this->tenant);
        $freshA = Preset::find($presetA->id);
        $freshB = Preset::find($presetB->id);

        $this->assertNotNull($freshA->seo_content, 'Preset A (Streamer) must have seo_content saved');
        $this->assertNull($freshB->seo_content, 'Preset B (Home Office) must not be touched');
    }

    // =========================================================================
    // Test 5: One preset throwing does NOT abort the batch
    // =========================================================================

    /** @test */
    public function command_one_failing_preset_does_not_abort_the_batch(): void
    {
        $failing = $this->makeLeafWithPreset('mixed-keyboards', 'Failing Preset');
        $passing = $this->makeLeafWithPreset('mixed-headsets', 'Passing Preset');

        $selective = $this->makeSelectiveThrower('Failing Preset');
        app()->instance(AiService::class, $selective);

        tenancy()->end();

        Artisan::call('pw2d:generate-preset-content', [
            'tenant' => 'preset-cmd-tenant',
        ]);

        // Both presets were attempted (calls = 2, one threw, one succeeded).
        $this->assertSame(2, $selective->calls, 'Both presets must be attempted — one failure must not abort the batch');

        tenancy()->initialize($this->tenant);
        $freshFailing = Preset::find($failing->id);
        $freshPassing = Preset::find($passing->id);

        $this->assertNull($freshFailing->seo_content, 'Failed preset must not have seo_content saved');
        $this->assertNotNull($freshPassing->seo_content, 'Passing preset must have seo_content saved despite the batch error');
    }

    // =========================================================================
    // Test 6: Returns FAILURE (exit code 1) if any preset errored
    // =========================================================================

    /** @test */
    public function command_returns_failure_if_any_preset_errored(): void
    {
        $thrower = $this->makeAiThrower();
        app()->instance(AiService::class, $thrower);

        $this->makeLeafWithPreset('failing-only', 'Bad Preset');

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:generate-preset-content', [
            'tenant' => 'preset-cmd-tenant',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode, 'Command must return FAILURE (1) when any preset errors');
    }

    // =========================================================================
    // Test 7: Returns SUCCESS (exit code 0) when all presets complete cleanly
    // =========================================================================

    /** @test */
    public function command_returns_success_when_all_presets_complete_cleanly(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        $this->makeLeafWithPreset('success-keyboards', 'Gamer');

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:generate-preset-content', [
            'tenant' => 'preset-cmd-tenant',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, 'Command must return SUCCESS (0) when all presets process cleanly');
    }

    // =========================================================================
    // Test 8: Returns FAILURE when tenant does not exist
    // =========================================================================

    /** @test */
    public function command_returns_failure_when_tenant_not_found(): void
    {
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:generate-preset-content', [
            'tenant' => 'nonexistent-tenant-xyz',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode, 'Command must return FAILURE when tenant is not found');
    }

    // =========================================================================
    // Test 9: Skips parent categories (only processes leaf categories)
    // =========================================================================

    /** @test */
    public function command_only_processes_presets_in_leaf_categories(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        // Parent category with a child — should be skipped.
        $parent = Category::factory()->create(['slug' => 'parent-cat-cmd', 'name' => 'Parent']);
        $parentFeature = Feature::factory()->create(['category_id' => $parent->id]);
        $parentPreset = Preset::factory()->create(['category_id' => $parent->id, 'name' => 'Parent Preset']);
        FeaturePreset::create(['preset_id' => $parentPreset->id, 'feature_id' => $parentFeature->id, 'weight' => 70]);

        // Leaf category under parent.
        $leaf = Category::factory()->create([
            'slug'      => 'leaf-cat-cmd',
            'name'      => 'Leaf',
            'parent_id' => $parent->id,
        ]);
        $leafFeature = Feature::factory()->create(['category_id' => $leaf->id]);
        $leafPreset = Preset::factory()->create(['category_id' => $leaf->id, 'name' => 'Leaf Preset']);
        FeaturePreset::create(['preset_id' => $leafPreset->id, 'feature_id' => $leafFeature->id, 'weight' => 80]);

        tenancy()->end();

        Artisan::call('pw2d:generate-preset-content', [
            'tenant' => 'preset-cmd-tenant',
        ]);

        $this->assertSame(1, $spy->calls, 'Only leaf category presets must be processed');
        $this->assertSame(['Leaf Preset'], $spy->calledPresetNames);

        tenancy()->initialize($this->tenant);
        $freshParentPreset = Preset::find($parentPreset->id);
        $freshLeafPreset   = Preset::find($leafPreset->id);

        $this->assertNull($freshParentPreset->seo_content, 'Parent category preset must not be processed');
        $this->assertNotNull($freshLeafPreset->seo_content, 'Leaf category preset must be processed');
    }

    // =========================================================================
    // Test 10: --category + --preset combined filter
    // =========================================================================

    /** @test */
    public function command_category_and_preset_filters_can_be_combined(): void
    {
        $spy = $this->makeAiSpy();
        app()->instance(AiService::class, $spy);

        // Category A with two presets.
        $categoryA = Category::factory()->create(['slug' => 'cat-a-combined', 'name' => 'Category A']);
        $featureA  = Feature::factory()->create(['category_id' => $categoryA->id]);

        $presetA1 = Preset::factory()->create(['category_id' => $categoryA->id, 'name' => 'Target Preset']);
        $presetA2 = Preset::factory()->create(['category_id' => $categoryA->id, 'name' => 'Other Preset']);
        FeaturePreset::create(['preset_id' => $presetA1->id, 'feature_id' => $featureA->id, 'weight' => 90]);
        FeaturePreset::create(['preset_id' => $presetA2->id, 'feature_id' => $featureA->id, 'weight' => 50]);

        // Category B (should be ignored by --category filter).
        $categoryB = Category::factory()->create(['slug' => 'cat-b-combined', 'name' => 'Category B']);
        $featureB  = Feature::factory()->create(['category_id' => $categoryB->id]);
        $presetB   = Preset::factory()->create(['category_id' => $categoryB->id, 'name' => 'Target Preset']);
        FeaturePreset::create(['preset_id' => $presetB->id, 'feature_id' => $featureB->id, 'weight' => 70]);

        tenancy()->end();

        Artisan::call('pw2d:generate-preset-content', [
            'tenant'     => 'preset-cmd-tenant',
            '--category' => 'cat-a-combined',
            '--preset'   => 'target-preset',  // Str::slug('Target Preset')
        ]);

        $this->assertSame(1, $spy->calls);
        $this->assertSame(['Target Preset'], $spy->calledPresetNames);

        tenancy()->initialize($this->tenant);
        $this->assertNotNull(Preset::find($presetA1->id)->seo_content, 'presetA1 must be saved');
        $this->assertNull(Preset::find($presetA2->id)->seo_content, 'presetA2 (different name) must not be touched');
        $this->assertNull(Preset::find($presetB->id)->seo_content, 'presetB (different category) must not be touched');
    }
}
