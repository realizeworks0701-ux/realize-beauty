<?php

use App\Http\Controllers\Api\GoogleCalendarWebhookController;
use App\Http\Controllers\Api\LineWebhookController;
use App\Http\Controllers\Api\PublicV1\PublicAvailabilityController;
use App\Http\Controllers\Api\PublicV1\PublicBookingController;
use App\Http\Controllers\Api\PublicV1\PublicReservationController;
use App\Http\Controllers\Api\PublicV1\PublicSalonController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingPageController;
use App\Http\Controllers\Api\V1\BusinessHourController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\GoogleCalendarController;
use App\Http\Controllers\Api\V1\LineSettingController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\RecordController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// LINE Webhook（全サロン共通・認証なし・署名検証で保護）
Route::post('/line/webhook', LineWebhookController::class);

// Google カレンダー push 通知（認証なし・throttle なし・channel_token 検証で保護。v1 プレフィックス外）
Route::post('/google/calendar/webhook', GoogleCalendarWebhookController::class);

// Stripe Webhook（認証なし・throttle なし・Stripe-Signature の HMAC 検証で保護。v1 プレフィックス外）
// 契約状態はフロントの申告ではなくこの経路でのみ同期する。DEV と本番で別の Webhook エンドポイントを
// 登録し、それぞれ Test / Live の STRIPE_WEBHOOK_SECRET を設定する（ADR-029）。
Route::post('/webhooks/stripe', StripeWebhookController::class);

// 公開Web予約（認証なし・throttle 必須）
Route::prefix('public/v1')->group(function () {

    Route::middleware('throttle:public-booking-read')->group(function () {
        Route::get('salons/{bookingSlug}', PublicSalonController::class);
        Route::get('salons/{bookingSlug}/availability', PublicAvailabilityController::class);
        Route::get('bookings/{bookingToken}', [PublicBookingController::class, 'show']);
    });

    Route::post('salons/{bookingSlug}/reservations', PublicReservationController::class)
        ->middleware('throttle:public-booking-create');

    Route::post('bookings/{bookingToken}/cancel', [PublicBookingController::class, 'cancel'])
        ->middleware('throttle:public-booking-cancel');
});

Route::prefix('v1')->group(function () {

    // Auth（認証なしのため総当たり対象。throttle 必須）
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login');

    // Google OAuth コールバック（認証なし。Google からのブラウザリダイレクトで Bearer を持たないため state で検証）
    Route::get('/google-calendar/callback', [GoogleCalendarController::class, 'callback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Customers（全プラン共通。契約が失効すると 403 になる）
        Route::middleware('feature:customer')->group(function () {
            Route::apiResource('customers', CustomerController::class);
        });

        // Records
        Route::middleware('feature:medical_record')->group(function () {
            Route::get('records', [RecordController::class, 'indexAll']);
            Route::get('customers/{customerId}/records', [RecordController::class, 'index']);
            Route::post('customers/{customerId}/records', [RecordController::class, 'store']);
            Route::get('records/{recordId}', [RecordController::class, 'show']);
            Route::patch('records/{recordId}', [RecordController::class, 'update']);
            Route::delete('records/{recordId}', [RecordController::class, 'destroy']);
        });

        // AI 要約（Pro のみ）
        Route::post('records/{recordId}/summarize', [RecordController::class, 'summarize'])
            ->middleware('feature:medical_record,ai_summary');

        // Photos
        Route::middleware('feature:photo')->group(function () {
            Route::post('records/{recordId}/photos', [PhotoController::class, 'store']);
            Route::delete('photos/{photoId}', [PhotoController::class, 'destroy']);
        });

        // Business Hours（営業時間はサロンの基本情報として全プランで編集できる）
        Route::get('business-hours', [BusinessHourController::class, 'index']);
        Route::put('business-hours', [BusinessHourController::class, 'update']);

        // 予約管理（Standard 以上）。メニューは施術時間・料金の定義であり
        // 予約と公開Web予約からのみ参照されるため同じ機能として扱う。
        Route::middleware('feature:reservation')->group(function () {
            // Menus
            Route::apiResource('menus', MenuController::class);

            // Reservations
            Route::get('reservations', [ReservationController::class, 'index']);
            Route::post('reservations', [ReservationController::class, 'store']);
            Route::get('reservations/{reservationId}', [ReservationController::class, 'show']);
            Route::patch('reservations/{reservationId}', [ReservationController::class, 'update']);
            Route::delete('reservations/{reservationId}', [ReservationController::class, 'destroy']);

            // Booking Page
            Route::get('booking-page', [BookingPageController::class, 'show']);
        });

        // Users
        Route::get('users', [UserController::class, 'index']);

        // LINE Settings（Standard 以上）
        Route::middleware('feature:line')->group(function () {
            Route::get('line-settings', [LineSettingController::class, 'show']);
            Route::put('line-settings', [LineSettingController::class, 'update']);
            Route::delete('line-settings', [LineSettingController::class, 'destroy']);
            Route::post('line-settings/verify', [LineSettingController::class, 'verify']);
        });

        // Google Calendar 連携設定（Standard 以上）
        Route::middleware('feature:google_calendar')->group(function () {
            Route::get('google-calendar', [GoogleCalendarController::class, 'index']);
            Route::get('google-calendar/busy-blocks', [GoogleCalendarController::class, 'busyBlocks']);
            Route::put('google-calendar/mode', [GoogleCalendarController::class, 'setMode']);
            Route::post('google-calendar/auth-url', [GoogleCalendarController::class, 'authUrl']);
            Route::get('google-calendar/connections/{connectionId}/calendars', [GoogleCalendarController::class, 'calendars']);
            Route::put('google-calendar/connections/{connectionId}', [GoogleCalendarController::class, 'updateConnection']);
            Route::delete('google-calendar/connections/{connectionId}', [GoogleCalendarController::class, 'destroy']);
        });

        // Subscription（契約の確認・開始・変更・解約。プラン制限の対象外）
        Route::get('subscription', [SubscriptionController::class, 'show']);
        Route::post('subscription/checkout', [SubscriptionController::class, 'checkout']);
        Route::post('subscription/sync-checkout', [SubscriptionController::class, 'syncCheckout']);
        Route::post('subscription/portal', [SubscriptionController::class, 'portal']);
        Route::post('subscription/change-plan', [SubscriptionController::class, 'changePlan']);
        Route::post('subscription/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('subscription/resume', [SubscriptionController::class, 'resume']);
    });
});
