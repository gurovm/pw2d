<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotsTxtRouteTest extends TestCase
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
        tenancy()->end();
        parent::tearDown();
    }

    public function test_robots_txt_uses_current_host_for_sitemap_directive(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        // The Sitemap directive must reference the current request's host, not pw2d.com
        $this->assertStringContainsString('Sitemap:', $response->getContent());
        $this->assertStringContainsString('/sitemap.xml', $response->getContent());
        $this->assertStringNotContainsString('pw2d.com', $response->getContent());
    }

    public function test_robots_txt_has_correct_content_type(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    }
}
