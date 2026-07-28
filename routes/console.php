<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('data:check-sources --triggered-by=scheduled')->dailyAt('09:00')->withoutOverlapping();

Schedule::command('enrich:run --triggered-by=scheduled')->weeklyOn(1, '10:00')->withoutOverlapping();
