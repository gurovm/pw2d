<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull SEO metrics (GSC + GA4) nightly at 03:00 for all enabled tenants.
// GSC keeps its own default window (--gsc-window-days=4, F19 — Search
// Console has a multi-day publish lag). GA4 was believed to have no lag
// (F19's original assumption) but that's wrong: day D is still being
// processed by GA4 at 03:00 UTC on D+1, so a 1-day pull permanently
// undercounts it. Measured on prod 2026-09-21 over 14 days: stored GA4
// sessions were only 69% (coffee2decide) / 42% (pw2d) of what GA4 reports
// for the same dates once fully processed. --ga4-window-days=3 re-pulls the
// last 3 days every night so each date gets caught up; this is safe because
// the upsert replaces values (never increments) and a failed click fetch no
// longer zeroes a previously stored ga4_outbound_clicks count (Spec 040).
// withoutOverlapping() prevents pile-ups if a previous run is still in
// progress (e.g. a large number of tenants).
Schedule::command('pw2d:seo:pull --ga4-window-days=3')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// Landing-page freshness audit (Spec 030) — nightly, slotted after the SEO
// pull. Catches drift no single event announces (price moves, selection
// changes); the instant observer/service-level path handles ignore-flip,
// detach, delete, and high_price flags as they happen. Non-zero exit when a
// PUBLISHED page is stale — same "check the exit code" ops habit as pw2d:seo:status.
Schedule::command('pw2d:landing-pages:audit')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();
