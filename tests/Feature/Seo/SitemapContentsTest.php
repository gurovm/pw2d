<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapContentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See SeoSchemaTest::setUp() for why we re-fetch via find().
        Tenant::create(['id' => 'acme', 'name' => 'Acme']);
        tenancy()->initialize(Tenant::find('acme'));
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    public function test_sitemap_includes_static_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('/about', $content);
        $this->assertStringContainsString('/contact', $content);
        $this->assertStringContainsString('/privacy-policy', $content);
        $this->assertStringContainsString('/terms-of-service', $content);
    }
}
