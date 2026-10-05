<?php

declare(strict_types=1);

namespace Tests\Feature\VsPages;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use App\Models\VsPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaveVsPageCommandTest extends TestCase
{
    use RefreshDatabase, VsFixtures;

    private Category $category;
    private Product $bianca;
    private Product $drive;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTenant('save-tenant');
        $this->category = Category::factory()->create(['name' => 'Espresso', 'slug' => 'espresso']);
        $this->bianca   = $this->makeLiveProduct($this->category, 'Lelit Bianca', 2000);
        $this->drive    = $this->makeLiveProduct($this->category, 'Profitec Drive', 2100);
        $this->file     = tempnam(sys_get_temp_dir(), 'vs');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        $this->endTenancy();
        parent::tearDown();
    }

    private function save(array $draft, array $options = []): int
    {
        file_put_contents($this->file, json_encode($draft));

        // The command initializes tenancy itself; start from a clean state like a CLI run.
        $this->endTenancy();
        $code = $this->artisan('pw2d:vs-pages:save', ['tenant' => 'save-tenant', 'file' => $this->file] + $options)->run();
        tenancy()->initialize(Tenant::find('save-tenant'));

        return $code;
    }

    private function assertRefused(int $code, string $label = ''): void
    {
        $this->assertSame(1, $code, $label);
        $this->assertSame(0, VsPage::count(), 'A refused save must write nothing ' . $label);
    }

    /** @test */
    public function it_saves_a_draft_with_slug_title_and_snapshots(): void
    {
        $code = $this->save($this->draftFor($this->bianca, $this->drive));

        $this->assertSame(0, $code);
        $page = VsPage::sole();
        $this->assertSame('lelit-bianca-vs-profitec-drive', $page->slug);
        $this->assertSame('Lelit Bianca vs Profitec Drive: scores, price and which to buy', $page->title);
        $this->assertSame('draft', $page->status);
        $this->assertSame(2000, $page->price_snapshot_a);
        $this->assertSame(2100, $page->price_snapshot_b);
        $this->assertNotNull($page->generated_at);
        $this->assertSame($this->category->id, $page->category_id);
        $this->assertSame('save-tenant', $page->tenant_id);
    }

    /** @test */
    public function it_orders_the_pair_alphabetically_and_swaps_the_sections_when_the_file_is_reversed(): void
    {
        $draft = $this->draftFor($this->drive, $this->bianca, [
            'sections' => ['a_wins' => '<p>DRIVE wins here.</p>', 'b_wins' => '<p>BIANCA wins here.</p>', 'who_should_buy' => '<p>x</p>'],
        ]);

        $this->assertSame(0, $this->save($draft));

        $page = VsPage::sole();
        $this->assertSame($this->bianca->id, $page->product_a_id);
        $this->assertSame($this->drive->id, $page->product_b_id);
        $this->assertSame('lelit-bianca-vs-profitec-drive', $page->slug);
        $this->assertSame('<p>BIANCA wins here.</p>', $page->sections['a_wins']);
        $this->assertSame('<p>DRIVE wins here.</p>', $page->sections['b_wins']);
        $this->assertSame(2000, $page->price_snapshot_a);
    }

    /** @test */
    public function resaving_a_pair_in_either_order_updates_in_place_keeping_slug_and_status(): void
    {
        $this->assertSame(0, $this->save($this->draftFor($this->bianca, $this->drive), ['--publish' => true]));
        $first = VsPage::sole();
        $this->assertSame('published', $first->status);

        $this->assertSame(0, $this->save($this->draftFor($this->drive, $this->bianca, ['intro' => '<p>Rewritten.</p>'])));

        $page = VsPage::sole();
        $this->assertSame($first->id, $page->id);
        $this->assertSame($first->slug, $page->slug);
        $this->assertSame('published', $page->status, 'status is kept without --publish');
        $this->assertSame('<p>Rewritten.</p>', $page->intro);
    }

    /** @test */
    public function publish_flag_publishes_an_existing_draft(): void
    {
        $this->save($this->draftFor($this->bianca, $this->drive));
        $this->assertSame('draft', VsPage::sole()->status);

        $this->save($this->draftFor($this->bianca, $this->drive), ['--publish' => true]);

        $this->assertSame('published', VsPage::sole()->status);
    }

    /** @test */
    public function it_refuses_a_product_from_another_tenant(): void
    {
        $this->endTenancy();
        Tenant::create(['id' => 'other-tenant', 'name' => 'Other']);
        tenancy()->initialize(Tenant::find('other-tenant'));
        $otherCat = Category::factory()->create(['name' => 'Espresso', 'slug' => 'espresso']);
        $foreign  = $this->makeLiveProduct($otherCat, 'Foreign 9', 2000);
        $this->endTenancy();
        tenancy()->initialize(Tenant::find('save-tenant'));

        $this->assertRefused($this->save($this->draftFor($this->bianca, $foreign)));
    }

    /** @test */
    public function it_refuses_different_categories(): void
    {
        $other = Category::factory()->create(['name' => 'Grinders', 'slug' => 'grinders']);
        $grinder = $this->makeLiveProduct($other, 'Eureka Grinder 5', 2000);

        $this->assertRefused($this->save($this->draftFor($this->bianca, $grinder)));
    }

    /** @test */
    public function it_refuses_a_product_that_is_not_live(): void
    {
        $this->drive->update(['is_ignored' => true]);

        $this->assertRefused($this->save($this->draftFor($this->bianca, $this->drive)));
    }

    /** @test */
    public function it_refuses_a_product_without_a_pick_eligible_offer(): void
    {
        ProductOffer::where('product_id', $this->drive->id)->update(['health_checked_at' => null]);

        $this->assertRefused($this->save($this->draftFor($this->bianca, $this->drive)));
    }

    /** @test */
    public function it_refuses_the_same_model(): void
    {
        $brand = Brand::factory()->create(['name' => 'Jura']);
        $black = $this->makeLiveProduct($this->category, 'Jura E8 Black', 1500, [], [], $brand);
        $white = $this->makeLiveProduct($this->category, 'Jura E8 White', 1500, [], [], $brand);

        $this->assertRefused($this->save($this->draftFor($black, $white)));
    }

    /** @test */
    public function it_refuses_when_a_price_moved_more_than_ten_percent_since_the_draft(): void
    {
        $draft = $this->draftFor($this->bianca, $this->drive);
        $draft['price_snapshot'][(string) $this->bianca->id] = 1700; // now 2000: +17.6%

        $this->assertRefused($this->save($draft));
    }

    /** @test */
    public function it_accepts_a_price_move_within_ten_percent(): void
    {
        $draft = $this->draftFor($this->bianca, $this->drive);
        $draft['price_snapshot'][(string) $this->bianca->id] = 1850; // now 2000: +8.1%

        $this->assertSame(0, $this->save($draft));
        $this->assertSame(2000, VsPage::sole()->price_snapshot_a, 'current price is stored, not the draft snapshot');
    }

    /** @test */
    public function it_refuses_a_missing_price_snapshot(): void
    {
        $draft = $this->draftFor($this->bianca, $this->drive);
        unset($draft['price_snapshot'][(string) $this->drive->id]);

        $this->assertRefused($this->save($draft));
    }

    /** @test */
    public function it_refuses_banned_phrases_in_any_prose_field(): void
    {
        $fields = [
            'intro'    => fn (array $d) => array_replace($d, ['intro' => '<p>A seamless pair.</p>']),
            'verdict'  => fn (array $d) => array_replace($d, ['verdict' => '<p>Look no further.</p>']),
            'a_wins'   => function (array $d) { $d['sections']['a_wins'] = '<p>It boasts speed.</p>'; return $d; },
            'faq q'    => function (array $d) { $d['faqs'][0]['q'] = 'Is it robust?'; return $d; },
            'faq a'    => function (array $d) { $d['faqs'][0]['a'] = '<p>A comprehensive answer.</p>'; return $d; },
        ];

        foreach ($fields as $label => $mutate) {
            $this->assertRefused($this->save($mutate($this->draftFor($this->bianca, $this->drive))), $label);
        }
    }

    /** @test */
    public function it_refuses_a_malformed_file(): void
    {
        $draft = $this->draftFor($this->bianca, $this->drive);
        unset($draft['verdict']);

        $this->assertRefused($this->save($draft));
    }

    /** @test */
    public function it_refuses_a_slug_already_used_by_a_different_pair(): void
    {
        $twin = $this->makeLiveProduct($this->category, 'Lelit Bianca', 2000, [], ['slug' => 'lelit-bianca-twin']);
        VsPage::factory()->create([
            'category_id' => $this->category->id, 'product_a_id' => $twin->id, 'product_b_id' => $this->drive->id,
            'slug' => 'lelit-bianca-vs-profitec-drive',
        ]);

        $code = $this->save($this->draftFor($this->bianca, $this->drive));

        $this->assertSame(1, $code);
        $this->assertSame(1, VsPage::count());
    }
}
