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

// Google カレンダー watch チャネルの張り直し（毎日 03:00 JST。止まると変更検知が静かに停止する）
Schedule::command('google-calendar:renew-channels')
    ->dailyAt('03:00')
    ->timezone(config('app.salon_timezone'));

// Google カレンダー同期窓の日次前進（毎日 04:00 JST。止まると60日で busy 取り込みが静かに停止する）
Schedule::command('google-calendar:refresh-sync')
    ->dailyAt('04:00')
    ->timezone(config('app.salon_timezone'));
