<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarMode;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Jobs\SyncGoogleCalendarJob;
use App\Jobs\SyncReservationToGoogleJob;
use App\Models\Customer;
use App\Models\GoogleBusyBlock;
use App\Models\GoogleCalendarConnection;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

/**
 * Googleカレンダー連携 管理側 API（OAuth・モード・接続管理・busy-blocks）。ADR-025 / google-calendar.md。
 */
class GoogleCalendarApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    private const NOW = '2026-07-20T08:00:00+09:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);

        Http::preventStrayRequests();
    }

    // ---- GET /google-calendar ----------------------------------------------

    public function test_index_returns_mode_and_connections_without_tokens(): void
    {
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->create([
            'user_id' => $user->id,
            'google_account_email' => 'misaki@example.com',
            'calendar_id' => 'primary',
            'last_synced_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/google-calendar');

        $response->assertOk();
        $response->assertJsonPath('data.mode', 'per_staff');
        $response->assertJsonPath('data.connections.0.id', $connection->id);
        $response->assertJsonPath('data.connections.0.user.id', $user->id);
        $response->assertJsonPath('data.connections.0.google_account_email', 'misaki@example.com');
        $response->assertJsonPath('data.connections.0.calendar_id', 'primary');
        $response->assertJsonPath('data.connections.0.status', 'active');

        $connectionJson = $response->json('data.connections.0');
        foreach (['access_token', 'refresh_token', 'sync_token', 'channel_token'] as $secret) {
            $this->assertArrayNotHasKey($secret, $connectionJson);
        }
    }

    public function test_index_returns_null_mode_and_empty_connections_when_unset(): void
    {
        $this->actingAsSalonUser();

        $response = $this->getJson('/api/v1/google-calendar');

        $response->assertOk();
        $response->assertJsonPath('data.mode', null);
        $response->assertJsonCount(0, 'data.connections');
    }

    public function test_index_is_scoped_to_own_salon(): void
    {
        $this->actingAsSalonUser();
        GoogleCalendarConnection::factory()->create(); // 別サロン

        $response = $this->getJson('/api/v1/google-calendar');

        $response->assertOk();
        $response->assertJsonCount(0, 'data.connections');
    }

    // ---- PUT /google-calendar/mode -----------------------------------------

    public function test_set_mode_persists(): void
    {
        $user = $this->actingAsSalonUser();

        $response = $this->putJson('/api/v1/google-calendar/mode', ['mode' => 'per_staff']);

        $response->assertOk();
        $response->assertJsonPath('data.mode', 'per_staff');
        $this->assertDatabaseHas('salons', [
            'id' => $user->salon_id,
            'google_calendar_mode' => 'per_staff',
        ]);
    }

    public function test_set_mode_rejects_invalid_value(): void
    {
        $this->actingAsSalonUser();

        $this->putJson('/api/v1/google-calendar/mode', ['mode' => 'both'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
    }

    public function test_changing_mode_disconnects_existing_connections_with_all_side_effects(): void
    {
        $this->fakeGoogle();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->create([
            'user_id' => $user->id,
            'channel_id' => 'ch-old',
            'channel_resource_id' => 'res-old',
        ]);
        $busy = GoogleBusyBlock::factory()->forConnection($connection)->create();
        $reservation = $this->reservation($salon, $user, ['google_event_id' => 'evt-1']);

        $response = $this->putJson('/api/v1/google-calendar/mode', ['mode' => 'shared']);

        $response->assertOk();
        $response->assertJsonPath('data.mode', 'shared');
        $response->assertJsonCount(0, 'data.connections');

        // 5手順: stop → revoke → busy 削除 → google_event_id クリア → 物理削除
        Http::assertSent(fn ($request) => str_contains($request->url(), '/channels/stop') && $request['id'] === 'ch-old');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/revoke'));
        $this->assertDatabaseMissing('google_calendar_connections', ['id' => $connection->id]);
        $this->assertDatabaseMissing('google_busy_blocks', ['id' => $busy->id]);
        $this->assertNull($reservation->fresh()->google_event_id);
        $this->assertDatabaseHas('salons', ['id' => $salon->id, 'google_calendar_mode' => 'shared']);
    }

    public function test_setting_same_mode_is_noop_and_keeps_connections(): void
    {
        Http::fake();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->create(['user_id' => $user->id]);

        $response = $this->putJson('/api/v1/google-calendar/mode', ['mode' => 'per_staff']);

        $response->assertOk();
        $this->assertDatabaseHas('google_calendar_connections', ['id' => $connection->id]);
        Http::assertNothingSent();
    }

    // ---- POST /google-calendar/auth-url ------------------------------------

    public function test_auth_url_stores_state_in_cache_and_returns_authorization_url(): void
    {
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);

        $response = $this->postJson('/api/v1/google-calendar/auth-url');

        $response->assertOk();
        $authUrl = $response->json('data.auth_url');
        $this->assertStringContainsString('accounts.google.com/o/oauth2/v2/auth', $authUrl);
        $this->assertStringContainsString('access_type=offline', $authUrl);
        $this->assertStringContainsString('prompt=consent', $authUrl);
        $this->assertStringContainsString('calendar.events', $authUrl);

        parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $params);
        $context = Cache::get('google_oauth_state:'.$params['state']);
        $this->assertSame($salon->id, $context['salon_id']);
        $this->assertSame($user->id, $context['user_id']);
        $this->assertSame('per_staff', $context['mode']);
    }

    public function test_auth_url_requires_mode(): void
    {
        $this->actingAsSalonUser();

        $this->postJson('/api/v1/google-calendar/auth-url')->assertStatus(422);
    }

    public function test_shared_mode_auth_url_stores_null_user(): void
    {
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::Shared]);
        $this->actingAsSalonUser($salon);

        $response = $this->postJson('/api/v1/google-calendar/auth-url');

        parse_str((string) parse_url($response->json('data.auth_url'), PHP_URL_QUERY), $params);
        $this->assertNull(Cache::get('google_oauth_state:'.$params['state'])['user_id']);
    }

    public function test_shared_mode_auth_url_forbidden_for_staff(): void
    {
        // shared の共有接続の作成・置換は owner / manager のみ。staff の API 直叩きは 403
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::Shared]);
        $staff = User::factory()->for($salon)->create(['role' => Role::Staff]);
        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/google-calendar/auth-url')->assertForbidden();
    }

    public function test_shared_mode_auth_url_allowed_for_manager(): void
    {
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::Shared]);
        $manager = User::factory()->for($salon)->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/google-calendar/auth-url')->assertOk();
    }

    public function test_per_staff_mode_auth_url_allowed_for_staff(): void
    {
        // per_staff は各自が自分のアカウントを接続するため全ロール可（shared の制限は適用しない）
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $staff = User::factory()->for($salon)->create(['role' => Role::Staff]);
        Sanctum::actingAs($staff);

        $this->postJson('/api/v1/google-calendar/auth-url')->assertOk();
    }

    // ---- GET /google-calendar/callback -------------------------------------

    public function test_callback_saves_connection_starts_watch_and_dispatches_initial_sync(): void
    {
        $this->fakeGoogle();
        Queue::fake();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $reservation = $this->reservation($salon, $user, [
            'start_at' => now()->addDays(3)->setTime(10, 0),
            'end_at' => now()->addDays(3)->setTime(11, 0),
        ]);

        $state = $this->startOAuth();
        $response = $this->get("/api/v1/google-calendar/callback?code=auth-code&state={$state}");

        $response->assertRedirect(rtrim(config('app.frontend_url'), '/').'/settings/google-calendar?connected=1');

        $connection = GoogleCalendarConnection::where('salon_id', $salon->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame($user->id, $connection->user_id);
        $this->assertSame('owner@example.com', $connection->google_account_email);
        $this->assertSame('primary', $connection->calendar_id);
        $this->assertNotNull($connection->channel_id);
        $this->assertSame('res-new', $connection->channel_resource_id);

        // 初回同期は受信（全同期）＋送信（reserved な対象予約）の両方を投入する
        Queue::assertPushed(SyncGoogleCalendarJob::class, fn ($job) => $job->connectionId === $connection->id);
        Queue::assertPushed(SyncReservationToGoogleJob::class, fn ($job) => $job->reservationId === $reservation->id);
    }

    public function test_callback_succeeds_even_when_watch_fails(): void
    {
        // watch 開設は best-effort（webhook は HTTPS + ドメイン所有権確認が前提で、未検証環境では
        // 必ず失敗する）。失敗しても接続保存・初回同期投入は完遂し、未開設チャネルは
        // 日次の renew-channels が開設する（ADR-025 §5）
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [
                    ['id' => 'owner@example.com', 'primary' => true, 'accessRole' => 'owner'],
                ],
            ]),
            'www.googleapis.com/calendar/v3/calendars/*/events/watch' => Http::response(['error' => 'boom'], 500),
        ]);
        Queue::fake();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $reservation = $this->reservation($salon, $user, [
            'start_at' => now()->addDays(3)->setTime(10, 0),
            'end_at' => now()->addDays(3)->setTime(11, 0),
        ]);

        $state = $this->startOAuth();
        $response = $this->get("/api/v1/google-calendar/callback?code=auth-code&state={$state}");

        $response->assertRedirect(rtrim(config('app.frontend_url'), '/').'/settings/google-calendar?connected=1');

        $connection = GoogleCalendarConnection::where('salon_id', $salon->id)->first();
        $this->assertNotNull($connection);
        $this->assertNull($connection->channel_id);

        Queue::assertPushed(SyncGoogleCalendarJob::class, fn ($job) => $job->connectionId === $connection->id);
        Queue::assertPushed(SyncReservationToGoogleJob::class, fn ($job) => $job->reservationId === $reservation->id);
    }

    public function test_callback_with_invalid_state_redirects_with_error(): void
    {
        $response = $this->get('/api/v1/google-calendar/callback?code=auth-code&state=does-not-exist');

        $response->assertRedirect(rtrim(config('app.frontend_url'), '/').'/settings/google-calendar?error=invalid_state');
        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_callback_with_access_denied_redirects_with_error(): void
    {
        $response = $this->get('/api/v1/google-calendar/callback?error=access_denied&state=whatever');

        $response->assertRedirect(rtrim(config('app.frontend_url'), '/').'/settings/google-calendar?error=access_denied');
    }

    public function test_callback_state_is_single_use(): void
    {
        $this->fakeGoogle();
        Queue::fake();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $this->actingAsSalonUser($salon);
        $state = $this->startOAuth();

        $this->get("/api/v1/google-calendar/callback?code=auth-code&state={$state}")
            ->assertRedirect(rtrim(config('app.frontend_url'), '/').'/settings/google-calendar?connected=1');

        // 2回目は state が消費済みで invalid_state
        $this->get("/api/v1/google-calendar/callback?code=auth-code&state={$state}")
            ->assertRedirect(rtrim(config('app.frontend_url'), '/').'/settings/google-calendar?error=invalid_state');
    }

    public function test_callback_rejects_state_when_mode_changed_after_issue(): void
    {
        $this->fakeGoogle();
        Queue::fake();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $this->actingAsSalonUser($salon);
        $state = $this->startOAuth();

        // state 発行後・コールバック前にモードが切り替わると食い違う接続を作りうるため拒否する
        $salon->update(['google_calendar_mode' => GoogleCalendarMode::Shared]);

        $this->get("/api/v1/google-calendar/callback?code=auth-code&state={$state}")
            ->assertRedirect(rtrim(config('app.frontend_url'), '/').'/settings/google-calendar?error=invalid_state');
        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_callback_reconnect_resets_calendar_to_primary_and_clears_event_ids(): void
    {
        // 再接続（同一 salon+user への callback）は calendar_id を primary に戻し、
        // calendar_id が旧値と異なるため google_event_id をクリアする（メールは同一でも）
        $this->fakeGoogle();
        Queue::fake();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $existing = GoogleCalendarConnection::factory()->for($salon)->create([
            'user_id' => $user->id,
            'google_account_email' => 'owner@example.com',
            'calendar_id' => 'team@group.calendar.google.com',
            'sync_token' => 'stored-token',
            'channel_id' => 'ch-old',
            'channel_resource_id' => 'res-old',
        ]);
        $busy = GoogleBusyBlock::factory()->forConnection($existing)->create();
        $reservation = $this->reservation($salon, $user, ['google_event_id' => 'evt-old']);

        $state = $this->startOAuth();
        $this->get("/api/v1/google-calendar/callback?code=auth-code&state={$state}")
            ->assertRedirect(rtrim(config('app.frontend_url'), '/').'/settings/google-calendar?connected=1');

        // 同じ行を更新する（新規作成しない）
        $this->assertDatabaseCount('google_calendar_connections', 1);
        $existing->refresh();
        $this->assertSame('primary', $existing->calendar_id);
        $this->assertNull($existing->sync_token);
        $this->assertNull($reservation->fresh()->google_event_id);
        $this->assertDatabaseMissing('google_busy_blocks', ['id' => $busy->id]);
    }

    public function test_callback_reconnect_same_account_and_primary_keeps_event_ids(): void
    {
        // メールも calendar_id（primary）も変わらない再接続では google_event_id を保持する
        $this->fakeGoogle();
        Queue::fake();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        GoogleCalendarConnection::factory()->for($salon)->create([
            'user_id' => $user->id,
            'google_account_email' => 'owner@example.com',
            'calendar_id' => 'primary',
        ]);
        $reservation = $this->reservation($salon, $user, ['google_event_id' => 'evt-keep']);

        $state = $this->startOAuth();
        $this->get("/api/v1/google-calendar/callback?code=auth-code&state={$state}")
            ->assertRedirect(rtrim(config('app.frontend_url'), '/').'/settings/google-calendar?connected=1');

        $this->assertSame('evt-keep', $reservation->fresh()->google_event_id);
    }

    // ---- GET /connections/{id}/calendars -----------------------------------

    public function test_calendars_returns_calendar_list_primary_first(): void
    {
        $this->fakeGoogle();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->create(['user_id' => $user->id]);

        $response = $this->getJson("/api/v1/google-calendar/connections/{$connection->id}/calendars");

        $response->assertOk();
        $response->assertJsonPath('data.0.id', 'owner@example.com');
        $response->assertJsonPath('data.0.primary', true);
        $response->assertJsonPath('data.1.id', 'team@group.calendar.google.com');

        // 読み取り専用（reader）カレンダーは書き込めないため一覧に出さない
        $ids = array_column($response->json('data'), 'id');
        $this->assertNotContains('holidays@group.v.calendar.google.com', $ids);
        $response->assertJsonCount(2, 'data');
    }

    public function test_calendars_excludes_read_only_calendars(): void
    {
        $this->fakeGoogle();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->create(['user_id' => $user->id]);

        $response = $this->getJson("/api/v1/google-calendar/connections/{$connection->id}/calendars");

        $response->assertOk();
        $this->assertNotContains(
            'holidays@group.v.calendar.google.com',
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_calendars_rejects_other_salon_connection_with_404(): void
    {
        $this->fakeGoogle();
        $this->actingAsSalonUser();
        $other = GoogleCalendarConnection::factory()->create();

        $this->getJson("/api/v1/google-calendar/connections/{$other->id}/calendars")->assertNotFound();
    }

    public function test_calendars_rejects_other_staff_per_staff_connection_with_404(): void
    {
        $this->fakeGoogle();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $this->actingAsSalonUser($salon); // owner だが per_staff の他人の接続は操作不可
        $colleague = User::factory()->for($salon)->create();
        $connection = GoogleCalendarConnection::factory()->for($salon)->create(['user_id' => $colleague->id]);

        $this->getJson("/api/v1/google-calendar/connections/{$connection->id}/calendars")->assertNotFound();
    }

    public function test_shared_connection_is_not_operable_by_staff_role(): void
    {
        $this->fakeGoogle();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::Shared]);
        $connection = GoogleCalendarConnection::factory()->for($salon)->shared()->create();

        $staff = User::factory()->for($salon)->create(['role' => Role::Staff]);
        Sanctum::actingAs($staff);

        $this->getJson("/api/v1/google-calendar/connections/{$connection->id}/calendars")->assertNotFound();
    }

    public function test_calendars_returns_422_for_needs_reconnect(): void
    {
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->needsReconnect()->create(['user_id' => $user->id]);

        $this->getJson("/api/v1/google-calendar/connections/{$connection->id}/calendars")->assertStatus(422);
    }

    // ---- PUT /connections/{id} ---------------------------------------------

    public function test_update_connection_changes_calendar_and_triggers_side_effects(): void
    {
        $this->fakeGoogle();
        Queue::fake();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->create([
            'user_id' => $user->id,
            'calendar_id' => 'primary',
            'sync_token' => 'stored-token',
            'channel_id' => 'ch-old',
            'channel_resource_id' => 'res-old',
        ]);
        $busy = GoogleBusyBlock::factory()->forConnection($connection)->create();
        $reservation = $this->reservation($salon, $user, [
            'google_event_id' => 'evt-old',
            'start_at' => now()->addDays(3)->setTime(10, 0),
            'end_at' => now()->addDays(3)->setTime(11, 0),
        ]);
        // 同期窓の外（本日+90日）の予約は初回送信同期の対象ではないが、旧カレンダーの
        // google_event_id は無効になるためクリアされなければならない
        $outOfWindow = $this->reservation($salon, $user, [
            'google_event_id' => 'evt-out',
            'start_at' => now()->addDays(90)->setTime(10, 0),
            'end_at' => now()->addDays(90)->setTime(11, 0),
        ]);

        $response = $this->putJson("/api/v1/google-calendar/connections/{$connection->id}", [
            'calendar_id' => 'team@group.calendar.google.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.calendar_id', 'team@group.calendar.google.com');

        $connection->refresh();
        $this->assertSame('team@group.calendar.google.com', $connection->calendar_id);
        $this->assertNull($connection->sync_token);
        $this->assertSame('res-new', $connection->channel_resource_id);

        // 旧チャネル停止 → 新カレンダーへ watch 張り直し
        Http::assertSent(fn ($request) => str_contains($request->url(), '/channels/stop') && $request['id'] === 'ch-old');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'calendars/team%40group.calendar.google.com/events/watch'));

        // busy 全削除 → 全同期で再構築 / 送信同期は旧カレンダーを previousCalendarId で受ける
        $this->assertDatabaseMissing('google_busy_blocks', ['id' => $busy->id]);
        Queue::assertPushed(SyncGoogleCalendarJob::class, fn ($job) => $job->connectionId === $connection->id);
        Queue::assertPushed(
            SyncReservationToGoogleJob::class,
            fn ($job) => $job->reservationId === $reservation->id && $job->previousCalendarId === 'primary',
        );

        // (5) ジョブ対象外（窓外・非 reserved）の旧カレンダー参照は null クリアする。
        // ジョブ対象の窓内 reserved は保持する — ジョブが実行時に google_event_id で
        // 旧カレンダーのイベントを削除するため、先にクリアすると孤児が残る
        $this->assertSame('evt-old', $reservation->fresh()->google_event_id);
        $this->assertNull($outOfWindow->fresh()->google_event_id);
        // 窓外予約は初回送信同期の対象外（ジョブは投入されない）
        Queue::assertNotPushed(
            SyncReservationToGoogleJob::class,
            fn ($job) => $job->reservationId === $outOfWindow->id,
        );
    }

    public function test_update_connection_dispatches_initial_sync_even_when_watch_fails(): void
    {
        // watch 開設が失敗しても calendar_id 永続化・busy 削除・初回同期（受信＋送信）投入は完遂し、
        // API は 200 を返す（打ち切ると送信同期が投入されず旧カレンダーへ孤児イベントが残り回復不能になる）
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [
                    ['id' => 'owner@example.com', 'primary' => true, 'accessRole' => 'owner'],
                    ['id' => 'team@group.calendar.google.com', 'primary' => false, 'accessRole' => 'writer'],
                ],
            ]),
            'www.googleapis.com/calendar/v3/channels/stop' => Http::response('', 204),
            'www.googleapis.com/calendar/v3/calendars/*/events/watch' => Http::response(['error' => 'boom'], 500),
        ]);
        Queue::fake();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->create([
            'user_id' => $user->id,
            'calendar_id' => 'primary',
            'channel_id' => 'ch-old',
            'channel_resource_id' => 'res-old',
        ]);
        $busy = GoogleBusyBlock::factory()->forConnection($connection)->create();
        $reservation = $this->reservation($salon, $user, [
            'start_at' => now()->addDays(3)->setTime(10, 0),
            'end_at' => now()->addDays(3)->setTime(11, 0),
        ]);

        $response = $this->putJson("/api/v1/google-calendar/connections/{$connection->id}", [
            'calendar_id' => 'team@group.calendar.google.com',
        ]);

        $response->assertOk();
        $this->assertSame('team@group.calendar.google.com', $connection->fresh()->calendar_id);
        $this->assertDatabaseMissing('google_busy_blocks', ['id' => $busy->id]);

        // watch 失敗でも初回同期（受信＋送信）は投入される
        Queue::assertPushed(SyncGoogleCalendarJob::class, fn ($job) => $job->connectionId === $connection->id);
        Queue::assertPushed(SyncReservationToGoogleJob::class, fn ($job) => $job->reservationId === $reservation->id);
    }

    public function test_update_connection_rejects_read_only_calendar(): void
    {
        // 読み取り専用（accessRole=reader）は events.insert が 403 になるため選べない
        $this->fakeGoogle();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->create(['user_id' => $user->id]);

        $this->putJson("/api/v1/google-calendar/connections/{$connection->id}", [
            'calendar_id' => 'holidays@group.v.calendar.google.com',
        ])->assertStatus(422)->assertJsonValidationErrors(['calendar_id']);
    }

    public function test_update_connection_rejects_unknown_calendar(): void
    {
        $this->fakeGoogle();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->create(['user_id' => $user->id]);

        $this->putJson("/api/v1/google-calendar/connections/{$connection->id}", [
            'calendar_id' => 'stranger@group.calendar.google.com',
        ])->assertStatus(422)->assertJsonValidationErrors(['calendar_id']);
    }

    public function test_update_connection_rejects_other_staff_with_404(): void
    {
        $this->fakeGoogle();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $this->actingAsSalonUser($salon);
        $colleague = User::factory()->for($salon)->create();
        $connection = GoogleCalendarConnection::factory()->for($salon)->create(['user_id' => $colleague->id]);

        $this->putJson("/api/v1/google-calendar/connections/{$connection->id}", ['calendar_id' => 'primary'])
            ->assertNotFound();
    }

    // ---- DELETE /connections/{id} ------------------------------------------

    public function test_delete_connection_runs_five_steps(): void
    {
        $this->fakeGoogle();
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->create([
            'user_id' => $user->id,
            'channel_id' => 'ch-1',
            'channel_resource_id' => 'res-1',
        ]);
        $busy = GoogleBusyBlock::factory()->forConnection($connection)->create();
        $reservation = $this->reservation($salon, $user, ['google_event_id' => 'evt-1']);

        $response = $this->deleteJson("/api/v1/google-calendar/connections/{$connection->id}");

        $response->assertNoContent();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/channels/stop') && $request['id'] === 'ch-1');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/revoke'));
        $this->assertDatabaseMissing('google_calendar_connections', ['id' => $connection->id]);
        $this->assertDatabaseMissing('google_busy_blocks', ['id' => $busy->id]);
        $this->assertNull($reservation->fresh()->google_event_id);
    }

    public function test_delete_needs_reconnect_connection_still_physically_deletes(): void
    {
        // needs_reconnect では stop / revoke が必ず失敗するが、解除は成功しなければならない
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
            'oauth2.googleapis.com/revoke' => Http::response(['error' => 'invalid_token'], 400),
        ]);
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $connection = GoogleCalendarConnection::factory()->for($salon)->needsReconnect()->expiredToken()->create([
            'user_id' => $user->id,
        ]);

        $this->deleteJson("/api/v1/google-calendar/connections/{$connection->id}")->assertNoContent();

        $this->assertDatabaseMissing('google_calendar_connections', ['id' => $connection->id]);
    }

    public function test_delete_rejects_other_salon_with_404(): void
    {
        $this->actingAsSalonUser();
        $other = GoogleCalendarConnection::factory()->create();

        $this->deleteJson("/api/v1/google-calendar/connections/{$other->id}")->assertNotFound();
        $this->assertDatabaseHas('google_calendar_connections', ['id' => $other->id]);
    }

    // ---- GET /google-calendar/busy-blocks ----------------------------------

    public function test_busy_blocks_returns_only_time_and_user_fields(): void
    {
        $user = $this->actingAsSalonUser();
        $connection = GoogleCalendarConnection::factory()->for($user->salon)->create(['user_id' => $user->id]);
        $busy = GoogleBusyBlock::factory()->forConnection($connection)->create([
            'start_at' => Carbon::parse('2026-07-20T13:00:00+09:00')->utc(),
            'end_at' => Carbon::parse('2026-07-20T14:00:00+09:00')->utc(),
        ]);

        $response = $this->getJson('/api/v1/google-calendar/busy-blocks?from=2026-07-20&to=2026-07-20');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $busy->id);
        $response->assertJsonPath('data.0.user_id', $user->id);
        $response->assertExactJson([
            'data' => [[
                'id' => $busy->id,
                'start_at' => $busy->start_at->toIso8601String(),
                'end_at' => $busy->end_at->toIso8601String(),
                'user_id' => $user->id,
            ]],
        ]);
    }

    public function test_busy_blocks_is_scoped_to_salon(): void
    {
        $this->actingAsSalonUser();
        $otherConnection = GoogleCalendarConnection::factory()->create();
        GoogleBusyBlock::factory()->forConnection($otherConnection)->create([
            'start_at' => Carbon::parse('2026-07-20T13:00:00+09:00')->utc(),
            'end_at' => Carbon::parse('2026-07-20T14:00:00+09:00')->utc(),
        ]);

        $response = $this->getJson('/api/v1/google-calendar/busy-blocks?from=2026-07-20&to=2026-07-20');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_busy_blocks_does_not_call_google(): void
    {
        Http::fake();
        $user = $this->actingAsSalonUser();
        $connection = GoogleCalendarConnection::factory()->for($user->salon)->create(['user_id' => $user->id]);
        GoogleBusyBlock::factory()->forConnection($connection)->create([
            'start_at' => Carbon::parse('2026-07-20T13:00:00+09:00')->utc(),
            'end_at' => Carbon::parse('2026-07-20T14:00:00+09:00')->utc(),
        ]);

        $this->getJson('/api/v1/google-calendar/busy-blocks?from=2026-07-20&to=2026-07-20')->assertOk();

        Http::assertNothingSent();
    }

    // ---- helpers -----------------------------------------------------------

    private function fakeGoogle(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ]),
            'oauth2.googleapis.com/revoke' => Http::response('', 200),
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [
                    ['id' => 'owner@example.com', 'primary' => true, 'summary' => 'メインカレンダー', 'accessRole' => 'owner'],
                    ['id' => 'team@group.calendar.google.com', 'primary' => false, 'summary' => 'チーム', 'accessRole' => 'writer'],
                    // 読み取り専用（accessRole=reader）は選択肢に出さない・選べない（events.insert が 403 になるため）
                    ['id' => 'holidays@group.v.calendar.google.com', 'primary' => false, 'summary' => '祝日', 'accessRole' => 'reader'],
                ],
            ]),
            'www.googleapis.com/calendar/v3/calendars/*/events/watch' => Http::response([
                'id' => 'ch-new',
                'resourceId' => 'res-new',
                'expiration' => '1783036800000',
            ]),
            'www.googleapis.com/calendar/v3/channels/stop' => Http::response('', 204),
        ]);
    }

    private function startOAuth(): string
    {
        $authUrl = $this->postJson('/api/v1/google-calendar/auth-url')->json('data.auth_url');
        parse_str((string) parse_url($authUrl, PHP_URL_QUERY), $params);

        return $params['state'];
    }

    private function reservation(Salon $salon, User $user, array $overrides = []): Reservation
    {
        return Reservation::factory()->create(array_merge([
            'salon_id' => $salon->id,
            'user_id' => $user->id,
            'customer_id' => Customer::factory()->for($salon),
            'menu_id' => Menu::factory()->for($salon),
            'status' => ReservationStatus::Reserved,
            'start_at' => now()->addDay()->setTime(10, 0),
            'end_at' => now()->addDay()->setTime(11, 0),
        ], $overrides));
    }
}
