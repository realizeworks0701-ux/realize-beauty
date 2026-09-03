<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Enums\GoogleCalendarMode;
use App\Enums\ReservationStatus;
use App\Jobs\SyncReservationToGoogleJob;
use App\Models\GoogleCalendarConnection;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use App\Repositories\ReservationRepository;
use App\Services\Billing\EntitlementService;
use App\Services\Google\GoogleEventSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleEventSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_creates_google_event_with_markers_for_new_reservation(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'evt-new'])]);

        [$salon, $staff, $menu] = $this->context(GoogleCalendarMode::PerStaff);
        $this->connection($salon, $staff);
        $reservation = $this->reservation($salon, $staff, $menu);

        $this->runSync($reservation->id);

        $this->assertSame('evt-new', $reservation->fresh()->google_event_id);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/calendars/primary/events')
            && $request['extendedProperties']['private']['rb_reservation_id'] === (string) $reservation->id
            && $request['extendedProperties']['private']['rb_salon_id'] === (string) $salon->id);
    }

    public function test_updates_existing_google_event(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'evt-1'])]);

        [$salon, $staff, $menu] = $this->context(GoogleCalendarMode::PerStaff);
        $this->connection($salon, $staff);
        $reservation = $this->reservation($salon, $staff, $menu, ['google_event_id' => 'evt-1']);

        $this->runSync($reservation->id);

        // events.update は PUT（全置換）ではなく PATCH（部分更新）で送る
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/calendars/primary/events/evt-1'));
    }

    public function test_shared_mode_includes_staff_name_in_summary(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'evt-new'])]);

        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::Shared]);
        $staff = User::factory()->for($salon)->create(['name' => '田中']);
        $menu = Menu::factory()->for($salon)->create(['name' => 'カット', 'duration_minutes' => 60]);
        $this->connection($salon, null); // shared = user_id null
        $reservation = $this->reservation($salon, $staff, $menu);

        $this->runSync($reservation->id);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['summary'] === 'カット（田中）');
    }

    public function test_deletes_event_and_clears_id_when_cancelled(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response('', 204)]);

        [$salon, $staff, $menu] = $this->context(GoogleCalendarMode::PerStaff);
        $this->connection($salon, $staff);
        $reservation = $this->reservation($salon, $staff, $menu, [
            'google_event_id' => 'evt-1',
            'status' => ReservationStatus::Cancelled,
        ]);

        $this->runSync($reservation->id);

        $this->assertNull($reservation->fresh()->google_event_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/calendars/primary/events/evt-1'));
    }

    public function test_deletes_event_when_no_show(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response('', 204)]);

        [$salon, $staff, $menu] = $this->context(GoogleCalendarMode::PerStaff);
        $this->connection($salon, $staff);
        $reservation = $this->reservation($salon, $staff, $menu, [
            'google_event_id' => 'evt-1',
            'status' => ReservationStatus::NoShow,
        ]);

        $this->runSync($reservation->id);

        $this->assertNull($reservation->fresh()->google_event_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_deletes_event_when_reservation_soft_deleted(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response('', 204)]);

        [$salon, $staff, $menu] = $this->context(GoogleCalendarMode::PerStaff);
        $this->connection($salon, $staff);
        $reservation = $this->reservation($salon, $staff, $menu, ['google_event_id' => 'evt-1']);
        $reservation->delete();

        $this->runSync($reservation->id);

        $this->assertNull(Reservation::withTrashed()->find($reservation->id)->google_event_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_staff_change_deletes_from_old_calendar_and_creates_in_new(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'POST'
                ? Http::response(['id' => 'evt-new'])
                : Http::response('', 204);
        });

        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $oldStaff = User::factory()->for($salon)->create();
        $newStaff = User::factory()->for($salon)->create();
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);

        $this->connection($salon, $oldStaff, ['access_token' => 'token-old']);
        $this->connection($salon, $newStaff, ['access_token' => 'token-new']);

        // 予約は現在 newStaff 担当・旧イベントIDを保持している
        $reservation = $this->reservation($salon, $newStaff, $menu, ['google_event_id' => 'evt-old']);

        $this->runSync($reservation->id, previousUserId: $oldStaff->id);

        $this->assertSame('evt-new', $reservation->fresh()->google_event_id);

        // 旧スタッフのカレンダーから削除（旧トークン）
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/events/evt-old')
            && $request->hasHeader('Authorization', 'Bearer token-old'));
        // 新スタッフのカレンダーへ作成（新トークン）
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer token-new'));
    }

    public function test_shared_mode_staff_change_updates_in_place_without_orphan_or_duplicate(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'evt-1'])]);

        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::Shared]);
        $oldStaff = User::factory()->for($salon)->create(['name' => '田中']);
        $newStaff = User::factory()->for($salon)->create(['name' => '鈴木']);
        $menu = Menu::factory()->for($salon)->create(['name' => 'カット', 'duration_minutes' => 60]);
        $this->connection($salon, null); // shared = 共有接続（user_id null）
        $reservation = $this->reservation($salon, $newStaff, $menu, ['google_event_id' => 'evt-1']);

        // 担当が oldStaff → newStaff に変わった（shared では書き込み先が変わらない）
        $this->runSync($reservation->id, previousUserId: $oldStaff->id);

        // 同一イベントを更新するだけ（旧イベントの削除・重複作成・ID差し替えを起こさない）
        $this->assertSame('evt-1', $reservation->fresh()->google_event_id);

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/calendars/primary/events/evt-1')
            && $request['summary'] === 'カット（鈴木）');
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_staff_change_with_needs_reconnect_old_connection_creates_in_new_without_failing(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['id' => 'evt-new'])
            : Http::response('', 204));

        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $oldStaff = User::factory()->for($salon)->create();
        $newStaff = User::factory()->for($salon)->create();
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);

        // 旧接続は needs_reconnect（削除を試みると認証失効でジョブごと落ちる状況）
        $oldConnection = $this->connection($salon, $oldStaff, [
            'status' => GoogleCalendarConnectionStatus::NeedsReconnect,
            'access_token' => 'token-old',
        ]);
        $this->connection($salon, $newStaff, ['access_token' => 'token-new']);

        $reservation = $this->reservation($salon, $newStaff, $menu, ['google_event_id' => 'evt-old']);

        $this->runSync($reservation->id, previousUserId: $oldStaff->id);

        // 新カレンダーへ作成し ID を差し替える（ジョブは落ちない）
        $this->assertSame('evt-new', $reservation->fresh()->google_event_id);
        // 旧接続が非アクティブのため削除は試みない
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
        // 新接続（新トークン）へ作成
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer token-new'));
        // 旧接続の状態には触れない
        $this->assertSame(GoogleCalendarConnectionStatus::NeedsReconnect, $oldConnection->fresh()->status);
    }

    public function test_delete_keeps_google_event_id_when_connection_inactive(): void
    {
        Http::fake();

        [$salon, $staff, $menu] = $this->context(GoogleCalendarMode::PerStaff);
        // 接続が needs_reconnect = 削除を実行できない
        $this->connection($salon, $staff, ['status' => GoogleCalendarConnectionStatus::NeedsReconnect]);
        $reservation = $this->reservation($salon, $staff, $menu, [
            'google_event_id' => 'evt-1',
            'status' => ReservationStatus::Cancelled,
        ]);

        $this->runSync($reservation->id);

        // 削除できていないため紐付けを保持する（null クリアすると受信同期で phantom busy 化する）
        $this->assertSame('evt-1', $reservation->fresh()->google_event_id);
        Http::assertNothingSent();
    }

    public function test_auth_retry_refreshes_token_and_retries_once_on_401(): void
    {
        $patchCount = 0;
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-refreshed', 'expires_in' => 3600]),
            'www.googleapis.com/*' => function () use (&$patchCount) {
                $patchCount++;

                // 1回目は 401、refresh 後の再試行（2回目）で成功する
                return $patchCount === 1
                    ? Http::response(['error' => ['code' => 401]], 401)
                    : Http::response(['id' => 'evt-1']);
            },
        ]);

        [$salon, $staff, $menu] = $this->context(GoogleCalendarMode::PerStaff);
        $connection = $this->connection($salon, $staff, ['access_token' => 'token-stale']);
        $reservation = $this->reservation($salon, $staff, $menu, ['google_event_id' => 'evt-1']);

        $this->runSync($reservation->id);

        // 再試行が成功したためジョブは打ち切られず、接続は active のまま
        $this->assertSame('evt-1', $reservation->fresh()->google_event_id);
        $this->assertSame(GoogleCalendarConnectionStatus::Active, $connection->fresh()->status);
        // 再試行の PATCH は refresh で得た新トークンで送られる
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && $request->hasHeader('Authorization', 'Bearer token-refreshed'));
    }

    public function test_update_404_falls_back_to_insert(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'PATCH') {
                return Http::response(['error' => ['code' => 404]], 404);
            }

            return Http::response(['id' => 'evt-recreated']);
        });

        [$salon, $staff, $menu] = $this->context(GoogleCalendarMode::PerStaff);
        $this->connection($salon, $staff);
        $reservation = $this->reservation($salon, $staff, $menu, ['google_event_id' => 'evt-gone']);

        $this->runSync($reservation->id);

        $this->assertSame('evt-recreated', $reservation->fresh()->google_event_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_reloads_reservation_at_execution_time(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'evt-new'])]);

        [$salon, $staff, $menu] = $this->context(GoogleCalendarMode::PerStaff);
        $this->connection($salon, $staff);

        $original = '2026-08-01T01:00:00Z';
        $reservation = $this->reservation($salon, $staff, $menu, ['start_at' => $original, 'end_at' => '2026-08-01T02:00:00Z']);

        // dispatch 後（ジョブ実行前）に予約を移動する
        app(ReservationRepository::class)->updateForSync($reservation, [
            'start_at' => '2026-08-01T05:00:00Z',
            'end_at' => '2026-08-01T06:00:00Z',
        ]);

        $this->runSync($reservation->id);

        // ジョブは実行時点の最新（05:00）を書く
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request['start']['dateTime'], '2026-08-01T05:00:00'));
    }

    public function test_auth_failure_marks_needs_reconnect(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        [$salon, $staff, $menu] = $this->context(GoogleCalendarMode::PerStaff);
        // 期限切れトークン → refresh を試みて invalid_grant で失効
        $connection = $this->connection($salon, $staff, ['token_expires_at' => now()->subMinute()]);
        $reservation = $this->reservation($salon, $staff, $menu);

        $this->runSync($reservation->id);

        $this->assertSame(GoogleCalendarConnectionStatus::NeedsReconnect, $connection->fresh()->status);
    }

    public function test_no_op_when_mode_not_configured(): void
    {
        Http::fake();

        $salon = Salon::factory()->create(['google_calendar_mode' => null]);
        $staff = User::factory()->for($salon)->create();
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);
        $reservation = $this->reservation($salon, $staff, $menu);

        $this->runSync($reservation->id);

        Http::assertNothingSent();
        $this->assertNull($reservation->fresh()->google_event_id);
    }

    /**
     * @return array{0: Salon, 1: User, 2: Menu}
     */
    private function context(GoogleCalendarMode $mode): array
    {
        $salon = Salon::factory()->create(['google_calendar_mode' => $mode]);
        $staff = User::factory()->for($salon)->create();
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);

        return [$salon, $staff, $menu];
    }

    private function connection(Salon $salon, ?User $user, array $overrides = []): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::factory()->create(array_merge([
            'salon_id' => $salon->id,
            'user_id' => $user?->id,
            'calendar_id' => 'primary',
            'status' => GoogleCalendarConnectionStatus::Active,
            'token_expires_at' => now()->addHour(),
        ], $overrides));
    }

    private function reservation(Salon $salon, User $staff, Menu $menu, array $overrides = []): Reservation
    {
        return Reservation::factory()->for($salon)->create(array_merge([
            'user_id' => $staff->id,
            'menu_id' => $menu->id,
        ], $overrides));
    }

    private function runSync(int $reservationId, ?int $previousUserId = null, ?string $previousCalendarId = null): void
    {
        (new SyncReservationToGoogleJob($reservationId, $previousUserId, $previousCalendarId))
            ->handle(
                app(ReservationRepository::class),
                app(GoogleEventSyncService::class),
                app(EntitlementService::class),
            );
    }
}
