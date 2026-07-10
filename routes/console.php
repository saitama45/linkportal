<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('portal:fetch-intake-emails')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('portal:flag-overdue-reviews')->dailyAt('08:00');
