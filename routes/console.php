<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull SEO metrics (GSC + GA4) nightly at 03:00 for all enabled tenants.
// Data for day D is available from Google ~1–2 hours after midnight UTC,
// so 03:00 gives a comfortable buffer. withoutOverlapping() prevents pile-ups
// if a previous run is still in progress (e.g. a large number of tenants).
Schedule::command('pw2d:seo:pull')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();
