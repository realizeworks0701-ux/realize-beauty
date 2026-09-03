<?php

namespace App\Providers;

use App\Services\Billing\EntitlementService;
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
        // 機能の利用可否判定は1リクエスト中に何度も走る（middleware・Service・Resource）ため、
        // サロンごとのプラン解決を1回のクエリに抑える。
        //
        // singleton ではなく scoped にする。queue:work は1プロセスで多数のジョブを処理し、
        // singleton だとワーカーが生きている間ずっと古いプランを掴み続ける
        // （解約・ダウングレードが反映されない）。scoped ならジョブごとに破棄される
        // （QueueServiceProvider が forgetScopedInstances() を呼ぶ）。
        $this->app->scoped(EntitlementService::class);
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
        // ログインは未認証で叩けるため総当たりの標的になる。
        // プロキシ配下ではクライアントIPが安定せず（Render は Cloudflare 配下）、
        // X-Forwarded-For は詐称もできるため、IPだけに頼ると歯止めにならない。
        // 詐称不可能なメールアドレス単位を主軸にし、IP単位は補助として重ねる。
        RateLimiter::for('auth-login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                // 主軸。IPを変えられても効く。正規利用者の打ち間違いでは到達しない緩さにする
                Limit::perMinutes(5, 20)->by('login-email:'.$email),
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

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
