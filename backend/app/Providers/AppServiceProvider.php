<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->passDbHostThroughServeCommand();
    }

    /**
     * artisan serve は許可リスト外の環境変数を配下のサーバープロセスから取り除くため、
     * Sail コンテナが渡す DB_HOST=pgsql が届かず .env の値（ホスト実行用の 127.0.0.1）に
     * フォールバックしてしまう。DB_HOST を許可リストに加えることで、コンテナ実行と
     * ホスト実行（composer dev）が同じ .env のまま両立する。
     */
    private function passDbHostThroughServeCommand(): void
    {
        if ($this->app->environment('local')) {
            ServeCommand::$passthroughVariables[] = 'DB_HOST';
        }
    }

    /**
     * 認証なしの公開Web予約APIの throttle（booking.md 非機能要件1）。
     */
    private function configureRateLimiting(): void
    {
        // ログインは未認証で叩けるため総当たりの標的になる。IP単位に加えて
        // メールアドレス+IP単位でも絞る（メール単位のみだと第三者が正規利用者を締め出せる）。
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(20)->by($request->ip()),
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
        ]);

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
