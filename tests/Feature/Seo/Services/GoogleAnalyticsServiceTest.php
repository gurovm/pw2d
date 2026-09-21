<?php

declare(strict_types=1);

namespace Tests\Feature\Seo\Services;

use App\Services\Seo\GoogleAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Spec 040 — parity test for the row-parsing helpers shared by
 * GoogleAnalyticsService::fetchLandingPageMetrics() and ::fetchOutboundClicks().
 *
 * No usable seam exists to drive the two public fetch methods end-to-end
 * without live Google credentials. GoogleSearchConsoleServiceTest fakes GSC by
 * overriding the protected makeClient() to return a `Google_Client` instance
 * (a non-final class) — but in practice that test never uses the returned
 * client either; it overrides the public method wholesale and reimplements the
 * bucketing logic against canned rows, so makeClient()'s return type is only
 * ever satisfied, never exercised.
 *
 * That trick does not carry over here: GoogleAnalyticsService::makeClient() is
 * typed to return Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient,
 * which is declared `final`. It cannot be subclassed, and Mockery cannot
 * partial-mock a final class either (it also relies on generating a runtime
 * subclass). Constructing the real client requires a valid service-account
 * credentials file, so even instantiating it in a test throws before any
 * fake row data could be injected.
 *
 * Given that, this test exercises the actual shared private methods —
 * extractDimensionValue() / extractMetricValue() — via Reflection. This is
 * real production code (not reimplemented test logic) and touches no network.
 * It directly verifies the parity claim documented on extractDimensionValue():
 * both fetch methods read a GA4 row's "url" identically, which is what lets
 * PullGa4Metrics merge landing-page and click rows by URL.
 */
class GoogleAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): GoogleAnalyticsService
    {
        // Never used to make a real API call — only its private parsing
        // methods are invoked below, so a dummy path is safe.
        return new GoogleAnalyticsService('properties/123456789', '/fake/path.json');
    }

    private function invokeExtractDimensionValue(GoogleAnalyticsService $service, mixed $row): string
    {
        $method = new ReflectionMethod(GoogleAnalyticsService::class, 'extractDimensionValue');
        $method->setAccessible(true);

        return $method->invoke($service, $row);
    }

    private function invokeExtractMetricValue(GoogleAnalyticsService $service, mixed $row, int $index, string $fallbackKey): float
    {
        $method = new ReflectionMethod(GoogleAnalyticsService::class, 'extractMetricValue');
        $method->setAccessible(true);

        return $method->invoke($service, $row, $index, $fallbackKey);
    }

    /**
     * Case 9 (Spec 040 tester brief): fetchOutboundClicks() and
     * fetchLandingPageMetrics() must produce the same `url` string for the
     * same GA4 path. Both call this exact private method with no
     * caller-specific branching, so the same array-fallback row shape used
     * by every fake in the suite must extract identically regardless of
     * which fetch method "owns" the call.
     */
    public function test_dimension_extraction_is_byte_identical_for_the_same_path_regardless_of_caller(): void
    {
        $service = $this->makeService();

        $landingRow = ['dimensions' => ['/compare/espresso-machines']];
        $clickRow   = ['dimensions' => ['/compare/espresso-machines']];

        $landingUrl = $this->invokeExtractDimensionValue($service, $landingRow);
        $clickUrl   = $this->invokeExtractDimensionValue($service, $clickRow);

        $this->assertSame('/compare/espresso-machines', $landingUrl);
        $this->assertSame(
            $landingUrl,
            $clickUrl,
            'fetchLandingPageMetrics() and fetchOutboundClicks() share one extractor — the url must be byte-identical for the same GA4 path so PullGa4Metrics can merge by URL',
        );
    }

    /**
     * Spec 040's corrected design: pagePath / landingPage are path-only
     * dimensions. The shared extractor must not introduce or infer a query
     * string — that would fragment merges (e.g. `/compare/x?preset=streamer`
     * never matching the `/compare/x` landing row).
     */
    public function test_dimension_extraction_never_introduces_a_query_string(): void
    {
        $service = $this->makeService();

        $row = ['dimensions' => ['/product/widget-pro']];

        $url = $this->invokeExtractDimensionValue($service, $row);

        $this->assertSame('/product/widget-pro', $url);
        $this->assertStringNotContainsString('?', $url);
    }

    /**
     * The two fetch methods read different metrics by name via $fallbackKey
     * in the array-fallback branch: fetchLandingPageMetrics() reads
     * 'sessions'/'users'/etc, fetchOutboundClicks() reads 'clicks'.
     */
    public function test_metric_extraction_reads_the_correct_fallback_key_for_each_caller(): void
    {
        $service = $this->makeService();

        $sessionsRow = ['metrics' => ['sessions' => 42]];
        $this->assertSame(42.0, $this->invokeExtractMetricValue($service, $sessionsRow, 0, 'sessions'));

        $clicksRow = ['metrics' => ['clicks' => 7]];
        $this->assertSame(7.0, $this->invokeExtractMetricValue($service, $clicksRow, 0, 'clicks'));
    }

    /**
     * Missing metric key must default to 0.0, not throw or return null —
     * both fetch methods immediately (int)/(float) cast this return value.
     */
    public function test_metric_extraction_defaults_to_zero_when_the_key_is_missing(): void
    {
        $service = $this->makeService();

        $this->assertSame(0.0, $this->invokeExtractMetricValue($service, ['metrics' => []], 0, 'clicks'));
    }
}
