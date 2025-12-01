<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule hold expiry release (backup safety net)
Schedule::command('release:expired-holds')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
