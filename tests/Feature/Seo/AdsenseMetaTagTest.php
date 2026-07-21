<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Google AdSense site-verification meta tag: emitted in the public layout
 * <head> only when `services.adsense.publisher_id` (ADSENSE_PUBLISHER_ID) is
 * filled; completely absent otherwise (filled() guard — null or empty string).
 */
class AdsenseMetaTagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See SeoSchemaTest::setUp() for why we re-fetch via find().
        Tenant::create(['id' => 'acme', 'name' => 'Acme']);
        $tenant = Tenant::find('acme');
        $tenant->brand_name = 'Acme Shop';
        $tenant->save();

        tenancy()->initialize($tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    /** @test */
    public function meta_tag_is_present_with_correct_content_when_publisher_id_is_configured(): void
    {
        config()->set('services.adsense.publisher_id', 'ca-pub-1234567890123456');

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(
            '<meta name="google-adsense-account" content="ca-pub-1234567890123456">',
            false
        );
    }

    /** @test */
    public function meta_tag_is_absent_when_publisher_id_is_null(): void
    {
        config()->set('services.adsense.publisher_id', null);

        $response = $this->get('/');

        $response->assertOk();
        $this->assertStringNotContainsString('google-adsense-account', $response->getContent());
    }

    /** @test */
    public function meta_tag_is_absent_when_publisher_id_is_empty_string(): void
    {
        config()->set('services.adsense.publisher_id', '');

        $response = $this->get('/');

        $response->assertOk();
        $this->assertStringNotContainsString('google-adsense-account', $response->getContent());
    }
}
