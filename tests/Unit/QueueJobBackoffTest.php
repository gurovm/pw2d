<?php

namespace Tests\Unit;

use App\Jobs\ProcessPendingProduct;
use App\Jobs\RescanProductFeatures;
use Tests\TestCase;

class QueueJobBackoffTest extends TestCase
{
    public function test_process_pending_product_has_escalating_backoff(): void
    {
        $job = new ProcessPendingProduct(1, 1);

        $this->assertSame([10, 60, 300], $job->backoff);
    }

    public function test_rescan_product_features_has_escalating_backoff(): void
    {
        $job = new RescanProductFeatures(1, 1);

        $this->assertSame([10, 60, 300], $job->backoff);
    }

    public function test_process_pending_product_has_three_tries(): void
    {
        $job = new ProcessPendingProduct(1, 1);

        $this->assertSame(3, $job->tries);
    }

    public function test_rescan_product_features_has_three_tries(): void
    {
        $job = new RescanProductFeatures(1, 1);

        $this->assertSame(3, $job->tries);
    }
}
