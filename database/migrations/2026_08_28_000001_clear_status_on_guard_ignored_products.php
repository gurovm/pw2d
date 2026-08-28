<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 038 B3 — data fix for the pre-B3 bug where a brand-new product that the
 * listing-health guard ignored on import (ACTION_FLAGGED_CONDITION) was never
 * dispatched to ProcessPendingProduct, but its stub `status = 'pending_ai'` was
 * never cleared either. These products are correctly ignored (invisible on the
 * site) and un-clearable by any retry path (ListProducts' "Retry Failed" only
 * targets `status = 'failed'`), so they were stuck at 'pending_ai' forever.
 *
 * Idempotent — safe to run more than once. Production has 28 such rows
 * (21 pw2d, 7 c2d) as of 2026-08-28.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->where('status', 'pending_ai')
            ->where('is_ignored', true)
            ->update(['status' => null]);
    }

    public function down(): void
    {
        // No-op: the previous state ('pending_ai' forever on an ignored
        // product) was the bug, not a state worth restoring.
    }
};
