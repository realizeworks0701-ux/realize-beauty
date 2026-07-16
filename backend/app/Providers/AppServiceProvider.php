<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * 認証なしの公開Web予約APIの throttle（booking.md 非機能要件1）。
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for(
            'public-booking-read',
            fn (Request $request) => Limit::perMinute(60)->by($request->ip()),
        );

        // 予約作成はIP単位に加えてサロン単位でも上限を設ける
        RateLimiter::for('public-booking-create', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(30)->by('booking-slug:'.$request->route('bookingSlug')),
        ]);

        RateLimiter::for(
            'public-booking-cancel',
            fn (Request $request) => Limit::perMinute(10)->by($request->ip()),
        );
    }
}
