<?php

namespace Tests\Feature;

use App\Models\GoogleBusyBlock;
use App\Models\GoogleCalendarConnection;
use App\Models\Salon;
use App\Models\User;
use App\Repositories\GoogleBusyBlockRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleBusyBlockRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private GoogleBusyBlockRepository $repository;

    private Salon $salon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new GoogleBusyBlockRepository;
        $this->salon = Salon::factory()->create();
    }

    public function test_upsert_updates_existing_block_for_same_event_id(): void
    {
        $connection = $this->connectionForUser($this->staff());

        $this->repository->upsertByEventId($connection, 'evt-1', $this->jst('10:00'), $this->jst('11:00'));
        $this->repository->upsertByEventId($connection, 'evt-1', $this->jst('14:00'), $this->jst('15:00'));

        $this->assertSame(1, GoogleBusyBlock::where('google_calendar_connection_id', $connection->id)->count());

        $block = GoogleBusyBlock::where('google_event_id', 'evt-1')->firstOrFail();
        $this->assertSame($connection->salon_id, $block->salon_id);
        $this->assertSame($connection->user_id, $block->user_id);
        $this->assertSame('2026-07-20 05:00:00', $block->start_at->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * JST の Carbon を渡しても timestamptz に UTC の instant として保存されること。
     */
    public function test_upsert_stores_jst_carbon_as_utc_instant(): void
    {
        $connection = $this->connectionForUser($this->staff());

        $block = $this->repository->upsertByEventId($connection, 'evt-1', $this->jst('10:00'), $this->jst('11:00'));

        $this->assertSame('2026-07-20 01:00:00', $block->fresh()->start_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-20 02:00:00', $block->fresh()->end_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_list_overlapping_returns_own_staff_block(): void
    {
        $staff = $this->staff();
        $connection = $this->connectionForUser($staff);
        $this->repository->upsertByEventId($connection, 'evt-1', $this->jst('10:00'), $this->jst('11:00'));

        $blocks = $this->repository->listOverlapping($this->salon->id, $staff->id, $this->jst('10:30'), $this->jst('10:45'));

        $this->assertCount(1, $blocks);
        $this->assertSame('evt-1', $blocks->first()->google_event_id);
    }

    public function test_list_overlapping_excludes_other_staff_block(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        $connection = $this->connectionForUser($other);
        $this->repository->upsertByEventId($connection, 'evt-1', $this->jst('10:00'), $this->jst('11:00'));

        $blocks = $this->repository->listOverlapping($this->salon->id, $owner->id, $this->jst('10:30'), $this->jst('10:45'));

        $this->assertCount(0, $blocks);
    }

    /**
     * shared 接続の busy（user_id = null）はサロン全体を塞ぐため、どのスタッフにも作用する。
     */
    public function test_list_overlapping_applies_shared_block_to_every_staff(): void
    {
        $shared = GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $this->salon->id]);
        $this->repository->upsertByEventId($shared, 'evt-holiday', $this->jst('10:00'), $this->jst('11:00'));

        foreach ([$this->staff(), $this->staff()] as $staff) {
            $blocks = $this->repository->listOverlapping($this->salon->id, $staff->id, $this->jst('10:30'), $this->jst('10:45'));

            $this->assertCount(1, $blocks, 'shared の busy は全スタッフを塞ぐ必要がある');
            $this->assertNull($blocks->first()->user_id);
        }
    }

    public function test_list_overlapping_excludes_other_salons(): void
    {
        $otherSalon = Salon::factory()->create();
        $otherConnection = GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $otherSalon->id]);
        $this->repository->upsertByEventId($otherConnection, 'evt-1', $this->jst('10:00'), $this->jst('11:00'));

        $blocks = $this->repository->listOverlapping($this->salon->id, $this->staff()->id, $this->jst('10:30'), $this->jst('10:45'));

        $this->assertCount(0, $blocks);
    }

    /**
     * 区間は [start, end) の半開区間。端が接するだけの枠は重複としない。
     */
    public function test_list_overlapping_treats_adjacent_ranges_as_not_overlapping(): void
    {
        $staff = $this->staff();
        $connection = $this->connectionForUser($staff);
        $this->repository->upsertByEventId($connection, 'evt-1', $this->jst('10:00'), $this->jst('11:00'));

        $before = $this->repository->listOverlapping($this->salon->id, $staff->id, $this->jst('09:00'), $this->jst('10:00'));
        $after = $this->repository->listOverlapping($this->salon->id, $staff->id, $this->jst('11:00'), $this->jst('12:00'));

        $this->assertCount(0, $before);
        $this->assertCount(0, $after);
    }

    /**
     * JST 09:00 の枠は UTC では前日 00:00。UTC 変換を欠くと9時間ずれてヒットしなくなる。
     */
    public function test_list_overlapping_matches_across_the_utc_date_boundary(): void
    {
        $staff = $this->staff();
        $connection = $this->connectionForUser($staff);

        // JST 2026-07-20 08:00-09:00 = UTC 2026-07-19 23:00-2026-07-20 00:00
        $this->repository->upsertByEventId($connection, 'evt-early', $this->jst('08:00'), $this->jst('09:00'));

        $blocks = $this->repository->listOverlapping($this->salon->id, $staff->id, $this->jst('08:30'), $this->jst('08:45'));

        $this->assertCount(1, $blocks);
        $this->assertSame('2026-07-19 23:00:00', $blocks->first()->start_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_list_by_salon_between_returns_overlapping_blocks_of_all_connections(): void
    {
        $staffConnection = $this->connectionForUser($this->staff());
        $sharedConnection = GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $this->salon->id]);

        $this->repository->upsertByEventId($staffConnection, 'evt-1', $this->jst('10:00'), $this->jst('11:00'));
        $this->repository->upsertByEventId($sharedConnection, 'evt-2', $this->jst('13:00'), $this->jst('14:00'));
        $this->repository->upsertByEventId($staffConnection, 'evt-out', $this->jst('20:00'), $this->jst('21:00'));

        $blocks = $this->repository->listBySalonBetween($this->salon->id, $this->jst('09:00'), $this->jst('15:00'));

        $this->assertSame(['evt-1', 'evt-2'], $blocks->pluck('google_event_id')->all());
    }

    public function test_delete_by_event_ids_only_touches_the_given_connection(): void
    {
        $connection = $this->connectionForUser($this->staff());
        $otherConnection = $this->connectionForUser($this->staff());

        $this->repository->upsertByEventId($connection, 'evt-1', $this->jst('10:00'), $this->jst('11:00'));
        $this->repository->upsertByEventId($otherConnection, 'evt-1', $this->jst('10:00'), $this->jst('11:00'));

        $deleted = $this->repository->deleteByEventIds($connection->id, ['evt-1']);

        $this->assertSame(1, $deleted);
        $this->assertSame(0, GoogleBusyBlock::where('google_calendar_connection_id', $connection->id)->count());
        $this->assertSame(1, GoogleBusyBlock::where('google_calendar_connection_id', $otherConnection->id)->count());
    }

    public function test_delete_by_event_ids_with_empty_list_is_a_no_op(): void
    {
        $connection = $this->connectionForUser($this->staff());
        $this->repository->upsertByEventId($connection, 'evt-1', $this->jst('10:00'), $this->jst('11:00'));

        $this->assertSame(0, $this->repository->deleteByEventIds($connection->id, []));
        $this->assertSame(1, GoogleBusyBlock::where('google_calendar_connection_id', $connection->id)->count());
    }

    public function test_delete_outside_window_keeps_blocks_overlapping_the_window(): void
    {
        $connection = $this->connectionForUser($this->staff());

        $this->repository->upsertByEventId($connection, 'evt-inside', $this->jst('12:00'), $this->jst('13:00'));
        $this->repository->upsertByEventId($connection, 'evt-straddling', $this->jst('08:00'), $this->jst('10:30'));
        $this->repository->upsertByEventId($connection, 'evt-before', $this->jst('07:00'), $this->jst('08:00'));
        $this->repository->upsertByEventId($connection, 'evt-after', $this->jst('18:00'), $this->jst('19:00'));

        $this->repository->deleteOutsideWindow($connection->id, $this->jst('10:00'), $this->jst('17:00'));

        $remaining = GoogleBusyBlock::where('google_calendar_connection_id', $connection->id)
            ->orderBy('start_at')
            ->pluck('google_event_id')
            ->all();

        $this->assertSame(['evt-straddling', 'evt-inside'], $remaining);
    }

    public function test_list_event_ids_between_returns_ids_for_reconcile(): void
    {
        $connection = $this->connectionForUser($this->staff());

        $this->repository->upsertByEventId($connection, 'evt-in', $this->jst('12:00'), $this->jst('13:00'));
        $this->repository->upsertByEventId($connection, 'evt-out', $this->jst('20:00'), $this->jst('21:00'));

        $ids = $this->repository->listEventIdsBetween($connection->id, $this->jst('10:00'), $this->jst('17:00'));

        $this->assertSame(['evt-in'], $ids);
    }

    private function staff(): User
    {
        return User::factory()->create(['salon_id' => $this->salon->id]);
    }

    private function connectionForUser(User $user): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::factory()->create([
            'salon_id' => $this->salon->id,
            'user_id' => $user->id,
        ]);
    }

    private function jst(string $time): Carbon
    {
        return Carbon::parse("2026-07-20 {$time}", 'Asia/Tokyo');
    }
}
