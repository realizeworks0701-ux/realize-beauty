<?php

namespace Tests\Feature;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Jobs\SendBookingConfirmationJob;
use App\Models\Customer;
use App\Models\LineSetting;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPublicBookingSalon;
use Tests\TestCase;

class PublicReservationApiTest extends TestCase
{
    use CreatesPublicBookingSalon, RefreshDatabase;

    private const NOW = '2026-07-20T08:00:00+09:00';

    private const START_AT = '2026-07-21T10:00:00+09:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
        Queue::fake();
    }

    public function test_creates_web_reservation_without_authentication(): void
    {
        [$salon, $menu, $staff] = $this->createContext();

        $response = $this->book($salon, ['user_id' => $staff->id]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => ['booking_token', 'start_at', 'end_at', 'menu_name', 'staff_name', 'line'],
        ]);
        $response->assertJsonPath('data.menu_name', $menu->name);
        $response->assertJsonPath('data.staff_name', $staff->name);

        $reservation = Reservation::sole();
        $this->assertSame($salon->id, $reservation->salon_id);
        $this->assertSame($staff->id, $reservation->user_id);
        $this->assertSame(ReservationStatus::Reserved, $reservation->status);
        $this->assertSame(ReservationSource::Web, $reservation->source);
        $this->assertTrue(Carbon::parse(self::START_AT)->eq($reservation->start_at));
        $this->assertTrue(Carbon::parse(self::START_AT)->addMinutes(60)->eq($reservation->end_at));
    }

    public function test_issues_booking_token_of_32_characters(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon);

        $response->assertCreated();
        $token = $response->json('data.booking_token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $token);
        $this->assertSame($token, Reservation::sole()->booking_token);
    }

    public function test_derives_end_at_from_menu_duration(): void
    {
        [$salon] = $this->createContext(durationMinutes: 90);

        $response = $this->book($salon);

        $response->assertCreated();
        $this->assertTrue(Carbon::parse(self::START_AT)->addMinutes(90)->eq(Reservation::sole()->end_at));
    }

    public function test_assigns_free_staff_in_id_order_when_staff_is_not_specified(): void
    {
        [$salon, , $first] = $this->createContext();
        User::factory()->for($salon)->create();

        $response = $this->book($salon);

        $response->assertCreated();
        $response->assertJsonPath('data.staff_name', $first->name);
        $this->assertSame($first->id, Reservation::sole()->user_id);
    }

    public function test_assigns_next_staff_when_the_first_one_is_busy(): void
    {
        [$salon, $menu, $first] = $this->createContext();
        $second = User::factory()->for($salon)->create();
        $this->reserve($salon, $first, $menu, self::START_AT);

        $response = $this->book($salon);

        $response->assertCreated();
        $response->assertJsonPath('data.staff_name', $second->name);
    }

    public function test_returns_422_when_all_staff_are_busy(): void
    {
        [$salon, $menu, $first] = $this->createContext();
        $second = User::factory()->for($salon)->create();
        $this->reserve($salon, $first, $menu, self::START_AT);
        $this->reserve($salon, $second, $menu, self::START_AT);

        $response = $this->book($salon);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_at');
    }

    public function test_returns_422_when_the_specified_staff_is_busy(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        User::factory()->for($salon)->create();
        $this->reserve($salon, $staff, $menu, self::START_AT);

        $response = $this->book($salon, ['user_id' => $staff->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_at');
    }

    public function test_returns_422_for_start_at_outside_business_hours(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['start_at' => '2026-07-21T08:00:00+09:00']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_at');
    }

    public function test_returns_422_for_start_at_off_the_30_minute_grid(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['start_at' => '2026-07-21T10:15:00+09:00']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_at');
    }

    public function test_returns_422_when_end_at_exceeds_close_time(): void
    {
        [$salon] = $this->createContext(durationMinutes: 90);

        $response = $this->book($salon, ['start_at' => '2026-07-21T18:00:00+09:00']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_at');
    }

    public function test_returns_422_when_start_at_is_within_30_minutes_from_now(): void
    {
        [$salon] = $this->createContext();
        $this->travelTo(Carbon::parse('2026-07-20T10:10:00+09:00'));

        $response = $this->book($salon, ['start_at' => '2026-07-20T10:30:00+09:00']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_at');
    }

    public function test_returns_422_when_start_at_is_beyond_60_days(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['start_at' => '2026-09-19T10:00:00+09:00']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_at');
    }

    public function test_returns_422_for_closed_day(): void
    {
        [$salon] = $this->createContext();
        $this->closeOn($salon, '2026-07-21');

        $response = $this->book($salon);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_at');
    }

    public function test_returns_422_for_start_at_without_offset(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['start_at' => '2026-07-21 10:00:00']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('start_at');
    }

    public function test_returns_422_for_inactive_menu(): void
    {
        [$salon] = $this->createContext();
        $menu = Menu::factory()->for($salon)->inactive()->create();

        $response = $this->book($salon, ['menu_id' => $menu->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('menu_id');
    }

    public function test_returns_422_for_staff_of_other_salon(): void
    {
        [$salon] = $this->createContext();
        $staff = User::factory()->for(Salon::factory())->create();

        $response = $this->book($salon, ['user_id' => $staff->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_id');
    }

    public function test_requires_customer_fields(): void
    {
        [$salon, $menu] = $this->createContext();

        $response = $this->postJson("/api/public/v1/salons/{$salon->booking_slug}/reservations", [
            'menu_id' => $menu->id,
            'start_at' => self::START_AT,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'kana', 'phone']);
    }

    public function test_matches_existing_customer_by_normalized_phone(): void
    {
        [$salon] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create([
            'name' => '山田 花子',
            'kana' => 'ヤマダ ハナコ',
            'phone' => '090-1234-5678',
        ]);

        $response = $this->book($salon, ['phone' => '09012345678', 'name' => '別名', 'kana' => 'ベツメイ']);

        $response->assertCreated();
        $this->assertSame(1, Customer::where('salon_id', $salon->id)->count());
        $this->assertSame($customer->id, Reservation::sole()->customer_id);

        // name / kana は上書きしない
        $customer->refresh();
        $this->assertSame('山田 花子', $customer->name);
        $this->assertSame('ヤマダ ハナコ', $customer->kana);
    }

    public function test_matches_existing_customer_by_full_width_phone(): void
    {
        [$salon] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create(['phone' => '09012345678']);

        $response = $this->book($salon, ['phone' => '０９０－１２３４－５６７８']);

        $response->assertCreated();
        $this->assertSame($customer->id, Reservation::sole()->customer_id);
    }

    public function test_matches_existing_customer_with_full_width_symbol_phone(): void
    {
        [$salon] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create(['phone' => '０３（１２３４）５６７８']);

        $fullWidth = $this->book($salon, ['phone' => '０３（１２３４）５６７８']);
        $halfWidth = $this->book($salon, ['start_at' => '2026-07-22T10:00:00+09:00', 'phone' => '03(1234)5678']);

        $fullWidth->assertCreated();
        $halfWidth->assertCreated();

        // 全角・半角いずれの入力でも既存顧客へ突合され、重複顧客は作成されない
        $this->assertSame(1, Customer::where('salon_id', $salon->id)->count());
        $this->assertSame([$customer->id], Reservation::query()->pluck('customer_id')->unique()->all());
    }

    public function test_counts_future_reservation_limit_across_phone_notations(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create(['phone' => '０３（１２３４）５６７８']);

        foreach (['2026-07-22T10:00:00+09:00', '2026-07-23T10:00:00+09:00', '2026-07-24T10:00:00+09:00'] as $startAt) {
            $this->reserve($salon, $staff, $menu, $startAt, customer: $customer);
        }

        $response = $this->book($salon, ['phone' => '03(1234)5678']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('phone');
    }

    public function test_matches_smallest_id_when_multiple_customers_share_phone(): void
    {
        [$salon] = $this->createContext();
        $first = Customer::factory()->for($salon)->create(['phone' => '090-1234-5678']);
        Customer::factory()->for($salon)->create(['phone' => '09012345678']);

        $response = $this->book($salon, ['phone' => '09012345678']);

        $response->assertCreated();
        $this->assertSame($first->id, Reservation::sole()->customer_id);
    }

    public function test_ignores_soft_deleted_and_other_salon_customers_when_matching(): void
    {
        [$salon] = $this->createContext();
        Customer::factory()->for($salon)->create(['phone' => '09012345678'])->delete();
        Customer::factory()->for(Salon::factory())->create(['phone' => '09012345678']);

        $response = $this->book($salon, ['phone' => '090-1234-5678', 'name' => '新規 顧客']);

        $response->assertCreated();
        $customer = Customer::where('salon_id', $salon->id)->sole();
        $this->assertSame('新規 顧客', $customer->name);
        $this->assertSame('09012345678', $customer->phone);
    }

    public function test_creates_new_customer_when_phone_does_not_match(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon, ['name' => '山田 花子', 'kana' => 'ヤマダ ハナコ', 'phone' => '090-1234-5678']);

        $response->assertCreated();
        $customer = Customer::where('salon_id', $salon->id)->sole();
        $this->assertSame('山田 花子', $customer->name);
        $this->assertSame('ヤマダ ハナコ', $customer->kana);
        $this->assertSame($customer->id, Reservation::sole()->customer_id);
    }

    public function test_returns_422_when_phone_already_has_three_future_reservations(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create(['phone' => '090-1234-5678']);

        foreach (['2026-07-22T10:00:00+09:00', '2026-07-23T10:00:00+09:00', '2026-07-24T10:00:00+09:00'] as $startAt) {
            $this->reserve($salon, $staff, $menu, $startAt, customer: $customer);
        }

        $response = $this->book($salon, ['phone' => '09012345678']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('phone');
    }

    public function test_allows_booking_when_past_and_cancelled_reservations_are_not_counted(): void
    {
        [$salon, $menu, $staff] = $this->createContext();
        $customer = Customer::factory()->for($salon)->create(['phone' => '090-1234-5678']);

        $this->reserve($salon, $staff, $menu, '2026-07-22T10:00:00+09:00', customer: $customer);
        $this->reserve($salon, $staff, $menu, '2026-07-23T10:00:00+09:00', customer: $customer);
        $this->reserve($salon, $staff, $menu, '2026-07-24T10:00:00+09:00', ReservationStatus::Cancelled, $customer);
        $this->reserve($salon, $staff, $menu, '2026-07-19T10:00:00+09:00', customer: $customer);

        $response = $this->book($salon, ['phone' => '09012345678']);

        $response->assertCreated();
    }

    public function test_acquires_phone_lock_before_staff_lock(): void
    {
        [$salon, , $staff] = $this->createContext();

        $lockKeys = [];
        DB::listen(function (QueryExecuted $query) use (&$lockKeys) {
            if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $lockKeys[] = $query->bindings[0];
            }
        });

        $this->book($salon)->assertCreated();

        // 同一 phone の同時リクエストを直列化する advisory lock をスタッフロックより先に取得する
        $this->assertSame([
            "booking-phone:{$salon->id}:09012345678",
            "reservation:{$salon->id}:{$staff->id}",
        ], $lockKeys);
    }

    public function test_issues_line_link_code_when_line_is_active_and_customer_is_not_linked(): void
    {
        [$salon] = $this->createContext();
        LineSetting::factory()->for($salon)->create(['bot_basic_id' => '@123abcd']);

        $response = $this->book($salon);

        $response->assertCreated();
        $response->assertJsonPath('data.line.add_friend_url', 'https://line.me/R/ti/p/@123abcd');

        $code = $response->json('data.line.link_code');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $code);

        $customer = Customer::sole();
        $this->assertSame($code, $customer->line_link_code);
        $this->assertTrue(now()->addHours(72)->eq($customer->line_link_code_expires_at));
        Queue::assertNothingPushed();
    }

    public function test_overwrites_link_code_on_the_next_booking(): void
    {
        [$salon] = $this->createContext();
        LineSetting::factory()->for($salon)->create();

        $first = $this->book($salon);
        $second = $this->book($salon, ['start_at' => '2026-07-22T10:00:00+09:00']);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertNotSame($first->json('data.line.link_code'), $second->json('data.line.link_code'));
        $this->assertSame($second->json('data.line.link_code'), Customer::sole()->line_link_code);
    }

    public function test_returns_null_line_when_salon_has_no_line_setting(): void
    {
        [$salon] = $this->createContext();

        $response = $this->book($salon);

        $response->assertCreated();
        $response->assertJsonPath('data.line', null);
        $this->assertNull(Customer::sole()->line_link_code);
    }

    public function test_returns_null_line_when_line_setting_is_inactive(): void
    {
        [$salon] = $this->createContext();
        LineSetting::factory()->for($salon)->inactive()->create();

        $response = $this->book($salon);

        $response->assertCreated();
        $response->assertJsonPath('data.line', null);
        $this->assertNull(Customer::sole()->line_link_code);
    }

    public function test_returns_null_line_and_dispatches_confirmation_for_linked_customer(): void
    {
        [$salon] = $this->createContext();
        LineSetting::factory()->for($salon)->create();
        $customer = Customer::factory()->for($salon)->create([
            'phone' => '09012345678',
            'line_user_id' => 'U0123456789',
            'line_linked_at' => now(),
        ]);

        $response = $this->book($salon, ['phone' => '090-1234-5678']);

        $response->assertCreated();
        $response->assertJsonPath('data.line', null);
        $this->assertNull($customer->refresh()->line_link_code);

        $reservationId = Reservation::sole()->id;
        Queue::assertPushed(
            SendBookingConfirmationJob::class,
            fn (SendBookingConfirmationJob $job) => $job->reservationId === $reservationId,
        );
    }

    public function test_does_not_dispatch_confirmation_for_unlinked_customer(): void
    {
        [$salon] = $this->createContext();
        LineSetting::factory()->for($salon)->create();

        $this->book($salon)->assertCreated();

        Queue::assertNothingPushed();
    }

    public function test_returns_404_for_inactive_salon(): void
    {
        [$salon] = $this->createContext();
        $salon->update(['is_active' => false]);

        $this->book($salon)->assertNotFound();
        $this->assertSame(0, Reservation::count());
    }

    public function test_throttles_reservation_creation_per_ip(): void
    {
        [$salon] = $this->createContext();

        foreach (range(1, 10) as $ignored) {
            $this->bookInvalid($salon)->assertStatus(422);
        }

        $this->bookInvalid($salon)->assertStatus(429);
    }

    public function test_throttles_reservation_creation_per_salon(): void
    {
        [$salon] = $this->createContext();
        [$otherSalon] = $this->createContext();

        // IP単位（10回/分）に達しないよう、IPを変えながらサロン単位の上限（30回/分）まで送る
        foreach (range(1, 30) as $count) {
            $this->fromIp('10.0.0.'.intdiv($count - 1, 3))->bookInvalid($salon)->assertStatus(422);
        }

        $this->fromIp('10.0.1.1')->bookInvalid($salon)->assertStatus(429);
        $this->fromIp('10.0.1.2')->bookInvalid($otherSalon)->assertStatus(422);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /**
     * throttle 検証用に、枠検証で必ず 422 になるリクエストを送る。
     */
    private function bookInvalid(Salon $salon): TestResponse
    {
        return $this->book($salon, ['start_at' => '2026-07-21T08:00:00+09:00']);
    }

    /**
     * @return array{0: Salon, 1: Menu, 2: User}
     */
    private function createContext(int $durationMinutes = 60): array
    {
        $salon = Salon::factory()->create();
        $this->createBusinessHours($salon);

        return [
            $salon,
            Menu::factory()->for($salon)->create(['duration_minutes' => $durationMinutes]),
            User::factory()->for($salon)->create(),
        ];
    }

    private function book(Salon $salon, array $overrides = []): TestResponse
    {
        $menuId = $overrides['menu_id'] ?? Menu::where('salon_id', $salon->id)->where('is_active', true)->value('id');

        return $this->postJson("/api/public/v1/salons/{$salon->booking_slug}/reservations", array_merge([
            'menu_id' => $menuId,
            'start_at' => self::START_AT,
            'name' => '山田 花子',
            'kana' => 'ヤマダ ハナコ',
            'phone' => '09012345678',
        ], $overrides));
    }

    private function reserve(
        Salon $salon,
        User $staff,
        Menu $menu,
        string $startAt,
        ReservationStatus $status = ReservationStatus::Reserved,
        ?Customer $customer = null,
    ): Reservation {
        $start = Carbon::parse($startAt)->utc();

        return Reservation::factory()->for($salon)->create([
            'customer_id' => $customer?->id ?? Customer::factory()->for($salon),
            'menu_id' => $menu->id,
            'user_id' => $staff->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes($menu->duration_minutes),
            'status' => $status,
        ]);
    }
}
