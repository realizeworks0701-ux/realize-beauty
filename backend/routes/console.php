<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 前日リマインダー（毎日 18:00 JST。時刻のサロン別設定はスコープ外）
Schedule::command('reservations:send-reminders')
    ->dailyAt('18:00')
    ->timezone(config('app.salon_timezone'));
