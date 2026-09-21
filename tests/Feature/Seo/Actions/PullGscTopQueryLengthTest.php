<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Actions;

use App\Actions\Seo\PullGscMetrics;
use App\Models\Tenant;
use App\Services\Seo\GoogleSearchConsoleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regression tests for the 2026-09-21 production incident: a 795-character
 * GSC "query" (someone pasted an AI system prompt into Google search) blew
 * past `gsc_top_query` VARCHAR(500) under MySQL strict mode, which failed the
 * entire multi-row upsert and silently dropped every other row for that
 * tenant/date. PullGscMetrics now truncates the top query defensively and
 * skips (never truncates) an over-long `url` instead.
 *
 * Mirrors the fake-service binding and tenant setup used in
 * PullGscMetricsTest — see that file for the established pattern.
 *
 * Note: sqlite (the local test driver) does not enforce VARCHAR length the
 * way MySQL strict mode does, so these tests assert on the stored value's
 * length/content directly rather than relying on the absence of a DB error.
 */
class PullGscTopQueryLengthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'acme', 'name' => 'Acme']);
        $this->tenant = Tenant::find('acme');
        $this->tenant->gsc_site_url = 'sc-domain:acme.com';
        $this->tenant->save();

        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    /**
     * Bind a fake GoogleSearchConsoleService implementing both
     * fetchUrlMetricsForRange() and fetchTopQueriesForRange() with canned
     * date-keyed data, matching PullGscMetricsTest::bindDualFakeService().
     */
    private function bindDualFakeService(Collection $urlMetricsBuckets, Collection $topQueryBuckets): void
    {
        app()->bind(GoogleSearchConsoleService::class, function () use ($urlMetricsBuckets, $topQueryBuckets) {
            return new class($urlMetricsBuckets, $topQueryBuckets) extends GoogleSearchConsoleService {
                public function __construct(
                    private readonly Collection $urlMetricsBuckets,
                    private readonly Collection $topQueryBuckets,
                ) {
                    parent::__construct('sc-domain:acme.com', '/fake/path.json');
                }

                public function fetchUrlMetricsForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    return $this->urlMetricsBuckets;
                }

                public function fetchTopQueriesForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
                {
                    return $this->topQueryBuckets;
                }
            };
        });
    }

    /**
     * (a) A 795-character top query is stored truncated to exactly 500
     * characters and the pull reports no error.
     */
    public function test_over_long_top_query_is_truncated_to_500_characters_with_no_error(): void
    {
        $date     = CarbonImmutable::parse('2026-04-10');
        $longQuery = str_repeat('q', 795);

        $urlMetricsBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/a', 'impressions' => 900, 'clicks' => 30, 'ctr' => 0.033, 'position' => 5.0, 'top_query' => null],
            ]),
        ]);

        $topQueryBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/a', 'top_query' => $longQuery, 'top_query_impressions' => 700],
            ]),
        ]);

        $this->bindDualFakeService($urlMetricsBuckets, $topQueryBuckets);

        $result = (new PullGscMetrics)->execute($this->tenant, [$date]);

        $this->assertSame(1, $result->upserted);
        $this->assertEmpty($result->errors);

        $row = DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('url', 'https://acme.com/a')
            ->where('metric_date', '2026-04-10')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(500, mb_strlen($row->gsc_top_query));
        $this->assertSame(mb_substr($longQuery, 0, 500), $row->gsc_top_query);
    }

    /**
     * (b) The regression that matters: one over-long top query in a batch
     * must not cause the other rows in the same batch to be dropped.
     */
    public function test_other_rows_in_the_same_batch_are_still_written_when_one_top_query_is_over_long(): void
    {
        $date      = CarbonImmutable::parse('2026-04-10');
        $longQuery = str_repeat('z', 795);

        $urlMetricsBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/a', 'impressions' => 1000, 'clicks' => 40, 'ctr' => 0.04, 'position' => 3.0, 'top_query' => null],
                ['url' => 'https://acme.com/b', 'impressions' => 500,  'clicks' => 20, 'ctr' => 0.04, 'position' => 5.0, 'top_query' => null],
                ['url' => 'https://acme.com/c', 'impressions' => 300,  'clicks' => 10, 'ctr' => 0.03, 'position' => 6.0, 'top_query' => null],
            ]),
        ]);

        $topQueryBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/a', 'top_query' => 'best espresso machine', 'top_query_impressions' => 800],
                ['url' => 'https://acme.com/b', 'top_query' => $longQuery,               'top_query_impressions' => 400],
                ['url' => 'https://acme.com/c', 'top_query' => 'affordable grinder',     'top_query_impressions' => 250],
            ]),
        ]);

        $this->bindDualFakeService($urlMetricsBuckets, $topQueryBuckets);

        $result = (new PullGscMetrics)->execute($this->tenant, [$date]);

        // All three rows must survive — this is the regression that mattered in prod.
        $this->assertSame(3, $result->upserted);
        $this->assertEmpty($result->errors);

        $rows = DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('metric_date', '2026-04-10')
            ->get()
            ->keyBy('url');

        $this->assertCount(3, $rows);
        $this->assertSame('best espresso machine', $rows['https://acme.com/a']->gsc_top_query);
        $this->assertSame(500, mb_strlen($rows['https://acme.com/b']->gsc_top_query));
        $this->assertSame(mb_substr($longQuery, 0, 500), $rows['https://acme.com/b']->gsc_top_query);
        $this->assertSame('affordable grinder', $rows['https://acme.com/c']->gsc_top_query);
    }

    /**
     * (c) A multibyte query longer than 500 characters truncates to exactly
     * 500 CHARACTERS (not bytes) without breaking a character or throwing.
     */
    public function test_multibyte_top_query_truncates_to_500_characters_without_breaking_a_character(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');

        // Hebrew letter aleph, one codepoint each — 600 characters, well over
        // the 500-character column limit but each character is multibyte in UTF-8.
        $multibyteQuery = str_repeat('א', 600);

        $urlMetricsBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/heb', 'impressions' => 600, 'clicks' => 15, 'ctr' => 0.025, 'position' => 8.0, 'top_query' => null],
            ]),
        ]);

        $topQueryBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/heb', 'top_query' => $multibyteQuery, 'top_query_impressions' => 200],
            ]),
        ]);

        $this->bindDualFakeService($urlMetricsBuckets, $topQueryBuckets);

        $result = (new PullGscMetrics)->execute($this->tenant, [$date]);

        $this->assertSame(1, $result->upserted);
        $this->assertEmpty($result->errors);

        $row = DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('url', 'https://acme.com/heb')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(500, mb_strlen($row->gsc_top_query));
        $this->assertSame(mb_substr($multibyteQuery, 0, 500), $row->gsc_top_query);
        // Confirm no broken/invalid encoding was produced by the truncation.
        $this->assertTrue(mb_check_encoding($row->gsc_top_query, 'UTF-8'));
    }

    /**
     * (d) A normal short query is stored unchanged.
     */
    public function test_normal_short_top_query_is_stored_unchanged(): void
    {
        $date = CarbonImmutable::parse('2026-04-10');

        $urlMetricsBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/short', 'impressions' => 400, 'clicks' => 12, 'ctr' => 0.03, 'position' => 6.5, 'top_query' => null],
            ]),
        ]);

        $topQueryBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/short', 'top_query' => 'best espresso machine', 'top_query_impressions' => 300],
            ]),
        ]);

        $this->bindDualFakeService($urlMetricsBuckets, $topQueryBuckets);

        (new PullGscMetrics)->execute($this->tenant, [$date]);

        $row = DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('url', 'https://acme.com/short')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('best espresso machine', $row->gsc_top_query);
    }

    /**
     * (e) A row whose URL exceeds the `url` VARCHAR(500) column is skipped
     * entirely (never truncated — that would desync url_hash), logs a
     * warning with tenant + date + URL length, and does not block the other
     * rows in the same batch.
     */
    public function test_row_with_over_long_url_is_skipped_and_logged_but_other_rows_survive(): void
    {
        Log::spy();

        $date      = CarbonImmutable::parse('2026-04-10');
        $longUrl   = 'https://acme.com/' . str_repeat('a', 490); // > 500 chars total

        $urlMetricsBuckets = collect([
            '2026-04-10' => collect([
                ['url' => $longUrl, 'impressions' => 300, 'clicks' => 5, 'ctr' => 0.017, 'position' => 12.0, 'top_query' => null],
                ['url' => 'https://acme.com/normal', 'impressions' => 700, 'clicks' => 22, 'ctr' => 0.031, 'position' => 4.5, 'top_query' => null],
            ]),
        ]);

        // No top-query entry for $longUrl needed — the row is skipped before
        // the top-query lookup is even consulted.
        $topQueryBuckets = collect([
            '2026-04-10' => collect([
                ['url' => 'https://acme.com/normal', 'top_query' => 'affordable grinder', 'top_query_impressions' => 150],
            ]),
        ]);

        $this->bindDualFakeService($urlMetricsBuckets, $topQueryBuckets);

        $result = (new PullGscMetrics)->execute($this->tenant, [$date]);

        // Only the well-formed row was upserted; the over-long URL was skipped, not an error.
        $this->assertSame(1, $result->upserted);
        $this->assertEmpty($result->errors);

        $rows = DB::table('seo_metrics')
            ->where('tenant_id', 'acme')
            ->where('metric_date', '2026-04-10')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('https://acme.com/normal', $rows->first()->url);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($longUrl) {
                return str_contains($message, 'over-long URL')
                    && ($context['tenant'] ?? null) === 'acme'
                    && ($context['date'] ?? null) === '2026-04-10'
                    && ($context['url_length'] ?? null) === mb_strlen($longUrl);
            });
    }
}
