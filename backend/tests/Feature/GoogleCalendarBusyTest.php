<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarMode;
use App\Models\Customer;
use App\Models\GoogleBusyBlock;
use App\Models\GoogleCalendarConnection;
use App\Models\Menu;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPublicBookingSalon;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

/**
 * 外部予定（busy）の空き枠・公開予約への反映（ADR-025 §7 / Business Rules 8）。
 */
class GoogleCalendarBusyTest extends TestCase
{
    use CreatesPublicBookingSalon, CreatesSalonUsers, RefreshDatabase;

    private const NOW = '2026-07-20T08:00:00+09:00';

    private const DATE = '2026-07-21';

    private const SLOT = '2026-07-21T10:00:00+09:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
    }

    public function test_per_staff_busy_excludes_slots_for_that_staff_only(): void
    {
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $this->createBusinessHours($salon);
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);
        $staffA = User::factory()->for($salon)->create();
        $staffB = User::factory()->for($salon)->create();

        $connection = GoogleCalendarConnection::factory()->for($salon)->create(['user_id' => $staffA->id]);
        $this->busyBlock($connection, self::SLOT, '2026-07-21T11:00:00+09:00');

        $forA = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staffA->id]);
        $forB = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id, 'user_id' => $staffB->id]);

        // 当該スタッフ（staffA）は busy と重なる枠が消える
        $this->assertNotContains(self::SLOT, $this->slotTimes($forA));
        $this->assertContains('2026-07-21T09:00:00+09:00', $this->slotTimes($forA));

        // 別スタッフ（staffB）の枠は塞がらない
        $this->assertContains(self::SLOT, $this->slotTimes($forB));
    }

    public function test_shared_busy_excludes_slots_for_all_staff(): void
    {
        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::Shared]);
        $this->createBusinessHours($salon);
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);
        User::factory()->for($salon)->count(2)->create();

        $connection = GoogleCalendarConnection::factory()->for($salon)->shared()->create();
        $this->busyBlock($connection, self::SLOT, '2026-07-21T11:00:00+09:00');

        // 指名なし: shared の busy（user_id=null）は全スタッフを塞ぐため 10:00 枠は消える
        $unnamed = $this->getSlots($salon, ['date' => self::DATE, 'menu_id' => $menu->id]);

        $this->assertNotContains(self::SLOT, $this->slotTimes($unnamed));
        $this->assertContains('2026-07-21T09:00:00+09:00', $this->slotTimes($unnamed));
    }

    public function test_public_booking_is_rejected_on_a_busy_slot(): void
    {
        Queue::fake();

        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $this->createBusinessHours($salon);
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);
        $staff = User::factory()->for($salon)->create();

        $connection = GoogleCalendarConnection::factory()->for($salon)->create(['user_id' => $staff->id]);
        $this->busyBlock($connection, self::SLOT, '2026-07-21T11:00:00+09:00');

        $response = $this->postJson("/api/public/v1/salons/{$salon->booking_slug}/reservations", [
            'menu_id' => $menu->id,
            'start_at' => self::SLOT,
            'user_id' => $staff->id,
            'name' => '山田 花子',
            'kana' => 'ヤマダ ハナコ',
            'phone' => '09012345678',
        ]);

        // 枠埋まりと同じ start_at エラー（外部予定の存在は開示しない）
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_at']);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_admin_can_book_over_a_busy_slot(): void
    {
        Queue::fake();

        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);
        $user = $this->actingAsSalonUser($salon);
        $customer = Customer::factory()->for($salon)->create();
        $menu = Menu::factory()->for($salon)->create(['duration_minutes' => 60]);

        $connection = GoogleCalendarConnection::factory()->for($salon)->create(['user_id' => $user->id]);
        $this->busyBlock($connection, '2026-08-10T10:00:00+09:00', '2026-08-10T11:00:00+09:00');

        $response = $this->postJson('/api/v1/reservations', [
            'customer_id' => $customer->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => '2026-08-10T10:00:00+09:00',
        ]);

        // 管理側は busy でも登録可能（ADR-023 の思想を維持）
        $response->assertCreated();
        $this->assertDatabaseCount('reservations', 1);
    }

    private function busyBlock(GoogleCalendarConnection $connection, string $startAt, string $endAt): GoogleBusyBlock
    {
        // timestamptz への保存は UTC 済みの Carbon を渡す（JST の Carbon を渡すと offset が落ちて9時間ずれる）
        return GoogleBusyBlock::factory()->forConnection($connection)->create([
            'start_at' => Carbon::parse($startAt)->utc(),
            'end_at' => Carbon::parse($endAt)->utc(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function slotTimes(TestResponse $response): array
    {
        return collect($response->json('data'))->pluck('start_at')->all();
    }

    private function getSlots(Salon $salon, array $query): TestResponse
    {
        return $this->getJson("/api/public/v1/salons/{$salon->booking_slug}/availability?".http_build_query($query));
    }
}
