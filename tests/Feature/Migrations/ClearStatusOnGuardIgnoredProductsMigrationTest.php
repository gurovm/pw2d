<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 038 B3 — data fix migration
 * `2026_08_28_000001_clear_status_on_guard_ignored_products.php`.
 *
 * RefreshDatabase already runs this migration once (against an empty
 * `products` table) before every test in the suite, so it can't be exercised
 * through the normal migrate flow here. Instead we seed the exact "before"
 * state and call the migration's own up() directly — the standard Laravel
 * idiom for testing an anonymous-class migration in isolation. Running it a
 * second time here is also a live idempotency check (per the spec: "idempotent
 * migration").
 */
class ClearStatusOnGuardIgnoredProductsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        /** @var Migration $migration */
        $migration = include database_path('migrations/2026_08_28_000001_clear_status_on_guard_ignored_products.php');
        $migration->up();
    }

    /** @test */
    public function only_the_guard_ignored_pending_ai_product_is_cleared(): void
    {
        $ignoredStuck = Product::factory()->create([
            'status'     => 'pending_ai',
            'is_ignored' => true,
        ]);

        $notIgnoredPending = Product::factory()->create([
            'status'     => 'pending_ai',
            'is_ignored' => false,
        ]);

        $this->runMigration();

        $this->assertNull($ignoredStuck->fresh()->status);
        $this->assertSame('pending_ai', $notIgnoredPending->fresh()->status);
    }

    /** @test */
    public function it_never_touches_a_product_with_a_different_status(): void
    {
        $failedIgnored = Product::factory()->create([
            'status'     => 'failed',
            'is_ignored' => true,
        ]);

        $processedIgnored = Product::factory()->create([
            'status'     => null,
            'is_ignored' => true,
        ]);

        $this->runMigration();

        $this->assertSame('failed', $failedIgnored->fresh()->status);
        $this->assertNull($processedIgnored->fresh()->status);
    }

    /** @test */
    public function it_is_idempotent_when_run_a_second_time(): void
    {
        $ignoredStuck = Product::factory()->create([
            'status'     => 'pending_ai',
            'is_ignored' => true,
        ]);

        $this->runMigration();
        $this->runMigration();

        $this->assertNull($ignoredStuck->fresh()->status);
    }
}
