<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Enums\GoogleCalendarMode;
use App\Enums\ReservationStatus;
use App\Jobs\SyncGoogleCalendarJob;
use App\Models\GoogleBusyBlock;
use App\Models\GoogleCalendarConnection;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use App\Repositories\GoogleCalendarConnectionRepository;
use App\Services\Google\GoogleCalendarSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 同期窓・日付境界を決定的にするため JST の平日で固定する
        $this->travelTo(Carbon::parse('2026-07-20 09:00:00', 'Asia/Tokyo'));
        Http::preventStrayRequests();
    }

    public function test_incremental_sync_applies_external_event_as_busy(): void
    {
        $connection = $this->connection();

        $this->fakeList([
            'items' => [$this->timedEvent('ext-1', '2026-07-22T15:00:00+09:00', '2026-07-22T16:00:00+09:00')],
            'nextSyncToken' => 'next-token',
        ]);

        $this->sync($connection);

        $busy = GoogleBusyBlock::sole();
        $this->assertSame('ext-1', $busy->google_event_id);
        $this->assertTrue(Carbon::parse('2026-07-22T15:00:00+09:00')->eq($busy->start_at));
        $this->assertSame('next-token', $connection->fresh()->sync_token);

        // 増分同期は保存済み syncToken を渡す
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), 'syncToken=saved-sync-token'));
    }

    public function test_paging_applies_all_pages_then_saves_final_sync_token(): void
    {
        $connection = $this->connection();

        Http::fake(function (Request $request) {
            // 2ページ目のみ nextSyncToken を持つ（中間ページは nextPageToken のみ）
            if (str_contains($request->url(), 'pageToken=page-2')) {
                return Http::response([
                    'items' => [$this->timedEvent('ext-b', '2026-07-23T15:00:00+09:00', '2026-07-23T16:00:00+09:00')],
                    'nextSyncToken' => 'final-token',
                ]);
            }

            return Http::response([
                'items' => [$this->timedEvent('ext-a', '2026-07-22T15:00:00+09:00', '2026-07-22T16:00:00+09:00')],
                'nextPageToken' => 'page-2',
            ]);
        });

        $this->sync($connection);

        $this->assertDatabaseHas('google_busy_blocks', ['google_event_id' => 'ext-a']);
        $this->assertDatabaseHas('google_busy_blocks', ['google_event_id' => 'ext-b']);
        // 全ページ適用後に最終ページの nextSyncToken を保存する
        $this->assertSame('final-token', $connection->fresh()->sync_token);
    }

    public function test_410_falls_back_to_full_sync(): void
    {
        $connection = $this->connection();

        Http::fake(function (Request $request) {
            // 増分同期（syncToken 付き）は 410
            if (str_contains($request->url(), 'syncToken=')) {
                return Http::response(['error' => ['code' => 410]], 410);
            }

            // 全同期（timeMin/timeMax）
            return Http::response([
                'items' => [$this->timedEvent('ext-full', '2026-07-22T15:00:00+09:00', '2026-07-22T16:00:00+09:00')],
                'nextSyncToken' => 'new-token',
            ]);
        });

        $this->sync($connection);

        $this->assertDatabaseHas('google_busy_blocks', ['google_event_id' => 'ext-full']);
        $this->assertSame('new-token', $connection->fresh()->sync_token);
    }

    public function test_full_sync_reconcile_deletes_ghost_busy(): void
    {
        $connection = $this->connection(['sync_token' => null]); // 全同期

        // 応答に現れない同期窓内の busy（Google 側で削除された幽霊 busy）
        GoogleBusyBlock::factory()->forConnection($connection)->create([
            'google_event_id' => 'ghost',
            'start_at' => '2026-07-25T05:00:00Z',
            'end_at' => '2026-07-25T06:00:00Z',
        ]);

        $this->fakeList([
            'items' => [$this->timedEvent('live', '2026-07-22T15:00:00+09:00', '2026-07-22T16:00:00+09:00')],
            'nextSyncToken' => 'token',
        ]);

        $this->sync($connection);

        $this->assertDatabaseMissing('google_busy_blocks', ['google_event_id' => 'ghost']);
        $this->assertDatabaseHas('google_busy_blocks', ['google_event_id' => 'live']);
    }

    public function test_marker_event_without_reservation_match_becomes_busy(): void
    {
        $connection = $this->connection();

        // 他サロンの ID を騙るマーカー付きイベント（突合しない）→ テナント境界を越えず busy になる
        $event = $this->timedEvent('marker-evt', '2026-07-22T15:00:00+09:00', '2026-07-22T16:00:00+09:00', [
            'extendedProperties' => ['private' => ['rb_reservation_id' => '999999', 'rb_salon_id' => '888']],
        ]);

        $this->fakeList(['items' => [$event], 'nextSyncToken' => 'token']);

        $this->sync($connection);

        $this->assertDatabaseHas('google_busy_blocks', ['google_event_id' => 'marker-evt']);
    }

    public function test_tombstone_cancels_matching_reservation(): void
    {
        $connection = $this->connection();
        $reservation = $this->rbReservation($connection, 'evt-x', ReservationStatus::Reserved);

        $this->fakeList(['items' => [['id' => 'evt-x', 'status' => 'cancelled']], 'nextSyncToken' => 'token']);

        $this->sync($connection);

        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
    }

    public function test_tombstone_does_not_override_no_show(): void
    {
        $connection = $this->connection();
        $reservation = $this->rbReservation($connection, 'evt-x', ReservationStatus::NoShow);

        $this->fakeList(['items' => [['id' => 'evt-x', 'status' => 'cancelled']], 'nextSyncToken' => 'token']);

        $this->sync($connection);

        // no_show を cancelled に潰さない（自らの削除のエコー）
        $this->assertSame(ReservationStatus::NoShow, $reservation->fresh()->status);
    }

    public function test_staleness_guard_keeps_newer_rb_edit(): void
    {
        $connection = $this->connection();
        $reservation = $this->rbReservation($connection, 'evt-m', ReservationStatus::Reserved, [
            'start_at' => '2026-07-22T01:00:00Z',
            'end_at' => '2026-07-22T02:00:00Z',
        ]);

        // Google 上は移動済みだが event.updated は予約より古い → RB の方が新しい → no-op
        $event = $this->timedEvent('evt-m', '2026-07-22T13:00:00+09:00', '2026-07-22T14:00:00+09:00', [
            'updated' => now()->subMinutes(5)->toRfc3339String(),
        ]);

        $this->fakeList(['items' => [$event], 'nextSyncToken' => 'token']);

        $this->sync($connection);

        $this->assertTrue(Carbon::parse('2026-07-22T01:00:00Z')->eq($reservation->fresh()->start_at));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_no_op_when_start_and_end_match(): void
    {
        $connection = $this->connection();
        $reservation = $this->rbReservation($connection, 'evt-m', ReservationStatus::Reserved, [
            'start_at' => '2026-07-22T01:00:00Z',
            'end_at' => '2026-07-22T02:00:00Z',
        ]);

        $event = $this->timedEvent('evt-m', '2026-07-22T10:00:00+09:00', '2026-07-22T11:00:00+09:00');

        $this->fakeList(['items' => [$event], 'nextSyncToken' => 'token']);

        $this->sync($connection);

        $this->assertTrue(Carbon::parse('2026-07-22T01:00:00Z')->eq($reservation->fresh()->start_at));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_move_is_reflected_when_no_conflict(): void
    {
        $connection = $this->connection();
        $reservation = $this->rbReservation($connection, 'evt-m', ReservationStatus::Reserved, [
            'start_at' => '2026-07-22T01:00:00Z',
            'end_at' => '2026-07-22T02:00:00Z',
        ]);

        // Google 側で 13:00 JST へ移動（新しい）。競合なし・営業時間内
        $event = $this->timedEvent('evt-m', '2026-07-22T13:00:00+09:00', '2026-07-22T14:00:00+09:00');

        $this->fakeList(['items' => [$event], 'nextSyncToken' => 'token']);

        $this->sync($connection);

        $fresh = $reservation->fresh();
        $this->assertTrue(Carbon::parse('2026-07-22T04:00:00Z')->eq($fresh->start_at));
        // end は常に start + menu.duration で再導出（60分）
        $this->assertTrue(Carbon::parse('2026-07-22T05:00:00Z')->eq($fresh->end_at));
        // 反映であって巻き戻しではない（events.update しない）
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_move_is_rolled_back_on_conflict(): void
    {
        $connection = $this->connection();
        $reservation = $this->rbReservation($connection, 'evt-m', ReservationStatus::Reserved, [
            'start_at' => '2026-07-22T01:00:00Z',
            'end_at' => '2026-07-22T02:00:00Z',
        ]);

        // 移動先（13:00 JST = 04:00Z）に別予約が既に存在する
        Reservation::factory()->create([
            'salon_id' => $connection->salon_id,
            'user_id' => $connection->user_id,
            'menu_id' => $reservation->menu_id,
            'start_at' => '2026-07-22T04:00:00Z',
            'end_at' => '2026-07-22T05:00:00Z',
            'status' => ReservationStatus::Reserved,
        ]);

        $event = $this->timedEvent('evt-m', '2026-07-22T13:00:00+09:00', '2026-07-22T14:00:00+09:00');

        $this->fakeList(['items' => [$event], 'nextSyncToken' => 'token']);

        $this->sync($connection);

        // 予約は動かさず RB の値で Google 側を巻き戻す
        $this->assertTrue(Carbon::parse('2026-07-22T01:00:00Z')->eq($reservation->fresh()->start_at));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/events/evt-m')
            && str_contains($request['start']['dateTime'], '2026-07-22T01:00:00'));
    }

    public function test_length_only_change_is_rolled_back(): void
    {
        $connection = $this->connection();
        $reservation = $this->rbReservation($connection, 'evt-m', ReservationStatus::Reserved, [
            'start_at' => '2026-07-22T01:00:00Z',
            'end_at' => '2026-07-22T02:00:00Z',
        ]);

        // start は一致・end だけ長くされた（下端ドラッグ）→ 巻き戻し
        $event = $this->timedEvent('evt-m', '2026-07-22T10:00:00+09:00', '2026-07-22T12:00:00+09:00');

        $this->fakeList(['items' => [$event], 'nextSyncToken' => 'token']);

        $this->sync($connection);

        $this->assertTrue(Carbon::parse('2026-07-22T02:00:00Z')->eq($reservation->fresh()->end_at));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/events/evt-m')
            && str_contains($request['end']['dateTime'], '2026-07-22T02:00:00'));
    }

    public function test_excluded_events_are_not_busy_and_remove_existing(): void
    {
        $connection = $this->connection();

        // 後から transparent 化された予定の既存 busy
        GoogleBusyBlock::factory()->forConnection($connection)->create([
            'google_event_id' => 'was-busy',
            'start_at' => '2026-07-22T06:00:00Z',
            'end_at' => '2026-07-22T07:00:00Z',
        ]);

        $this->fakeList([
            'items' => [
                $this->timedEvent('was-busy', '2026-07-22T15:00:00+09:00', '2026-07-22T16:00:00+09:00', ['transparency' => 'transparent']),
                $this->timedEvent('wl', '2026-07-22T15:00:00+09:00', '2026-07-22T16:00:00+09:00', ['eventType' => 'workingLocation']),
                $this->timedEvent('bd', '2026-07-22T15:00:00+09:00', '2026-07-22T16:00:00+09:00', ['eventType' => 'birthday']),
                $this->timedEvent('dec', '2026-07-22T15:00:00+09:00', '2026-07-22T16:00:00+09:00', [
                    'attendees' => [['self' => true, 'responseStatus' => 'declined']],
                ]),
            ],
            'nextSyncToken' => 'token',
        ]);

        $this->sync($connection);

        // 除外化で既存 busy が消え、除外3種はいずれも busy にならない
        $this->assertSame(0, GoogleBusyBlock::count());
    }

    public function test_multiday_all_day_event_is_single_block(): void
    {
        $connection = $this->connection();

        $event = [
            'id' => 'holiday',
            'status' => 'confirmed',
            'start' => ['date' => '2026-07-25'],
            'end' => ['date' => '2026-07-28'], // 排他（7/25-7/27 の3日間）
            'updated' => now()->toRfc3339String(),
        ];

        $this->fakeList(['items' => [$event], 'nextSyncToken' => 'token']);

        $this->sync($connection);

        $busy = GoogleBusyBlock::sole();
        $this->assertTrue(Carbon::parse('2026-07-25 00:00:00', 'Asia/Tokyo')->eq($busy->start_at));
        $this->assertTrue(Carbon::parse('2026-07-28 00:00:00', 'Asia/Tokyo')->eq($busy->end_at));
    }

    public function test_shared_mode_busy_has_null_user_id(): void
    {
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::Shared]);
        $connection = GoogleCalendarConnection::factory()->create([
            'salon_id' => $salon->id,
            'user_id' => null,
            'calendar_id' => 'primary',
            'status' => GoogleCalendarConnectionStatus::Active,
            'token_expires_at' => now()->addHour(),
            'sync_token' => 'saved-sync-token',
        ]);

        $this->fakeList([
            'items' => [$this->timedEvent('ext-1', '2026-07-22T15:00:00+09:00', '2026-07-22T16:00:00+09:00')],
            'nextSyncToken' => 'token',
        ]);

        $this->sync($connection);

        $busy = GoogleBusyBlock::sole();
        $this->assertNull($busy->user_id);
        $this->assertSame($salon->id, $busy->salon_id);
    }

    public function test_job_skips_needs_reconnect_connection(): void
    {
        Http::fake();
        $connection = $this->connection(['status' => GoogleCalendarConnectionStatus::NeedsReconnect]);

        (new SyncGoogleCalendarJob($connection->id))->handle(
            app(GoogleCalendarConnectionRepository::class),
            app(GoogleCalendarSyncService::class),
        );

        Http::assertNothingSent();
    }

    private function connection(array $overrides = []): GoogleCalendarConnection
    {
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $staff = User::factory()->for($salon)->create();

        return GoogleCalendarConnection::factory()->create(array_merge([
            'salon_id' => $salon->id,
            'user_id' => $staff->id,
            'calendar_id' => 'primary',
            'status' => GoogleCalendarConnectionStatus::Active,
            'token_expires_at' => now()->addHour(),
            'sync_token' => 'saved-sync-token',
        ], $overrides));
    }

    private function rbReservation(
        GoogleCalendarConnection $connection,
        string $eventId,
        ReservationStatus $status,
        array $overrides = [],
    ): Reservation {
        $menu = Menu::factory()->create(['salon_id' => $connection->salon_id, 'duration_minutes' => 60]);

        return Reservation::factory()->create(array_merge([
            'salon_id' => $connection->salon_id,
            'user_id' => $connection->user_id,
            'menu_id' => $menu->id,
            'google_event_id' => $eventId,
            'status' => $status,
            'start_at' => '2026-07-22T01:00:00Z',
            'end_at' => '2026-07-22T02:00:00Z',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function timedEvent(string $id, string $start, string $end, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'status' => 'confirmed',
            'start' => ['dateTime' => $start],
            'end' => ['dateTime' => $end],
            'updated' => now()->addMinutes(5)->toRfc3339String(),
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fakeList(array $body): void
    {
        Http::fake(function (Request $request) use ($body) {
            // listEvents は GET。巻き戻しの events.update（PUT）等はダミー成功で応答する
            return $request->method() === 'GET'
                ? Http::response($body)
                : Http::response(['id' => 'evt'], 200);
        });
    }

    private function sync(GoogleCalendarConnection $connection): void
    {
        app(GoogleCalendarSyncService::class)->sync($connection);
    }
}
