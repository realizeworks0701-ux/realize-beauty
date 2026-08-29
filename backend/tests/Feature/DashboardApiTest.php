<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesSalonUsers;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use CreatesSalonUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 月中に固定して月境界・休眠90日境界のフレークを防ぐ（JST 2026-08-20）
        Carbon::setTestNow(Carbon::parse('2026-08-20T12:00:00+09:00'));
    }

    public function test_index_returns_summary_structure(): void
    {
        $this->actingAsSalonUser();

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'kpis' => [
                    'new_customers' => ['current', 'previous'],
                    'reservations' => ['current', 'previous'],
                    'sales' => ['current', 'previous'],
                    'repeat_rate' => ['current', 'previous'],
                ],
                'sales_trend',
                'today_reservations',
                'popular_menus',
                'customer_segments' => ['new', 'repeat', 'dormant', 'other'],
            ],
        ]);
        $response->assertJsonCount(6, 'data.sales_trend');
    }

    public function test_sales_kpi_sums_visited_reservation_prices_by_month(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        // 当月: visited 12000 + 8000。reserved / cancelled は含めない
        $this->reservationAt($user, $menu, '2026-08-05T10:00:00+09:00', ReservationStatus::Visited, 12000);
        $this->reservationAt($user, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Visited, 8000);
        $this->reservationAt($user, $menu, '2026-08-12T10:00:00+09:00', ReservationStatus::Reserved, 9000);
        $this->reservationAt($user, $menu, '2026-08-15T10:00:00+09:00', ReservationStatus::Cancelled, 7000);

        // 前月: visited 30000
        $this->reservationAt($user, $menu, '2026-07-10T10:00:00+09:00', ReservationStatus::Visited, 30000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.kpis.sales.current', 20000);
        $response->assertJsonPath('data.kpis.sales.previous', 30000);
    }

    public function test_monthly_aggregates_use_jst_boundary(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        // JST 8/1 00:30 = UTC 7/31 15:30 → 当月に含める
        $this->reservationAt($user, $menu, '2026-08-01T00:30:00+09:00', ReservationStatus::Visited, 5000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.kpis.sales.current', 5000);
        $response->assertJsonPath('data.kpis.sales.previous', 0);
    }

    public function test_new_customers_and_reservations_kpis_compare_with_previous_month(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-08-03']);
        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-08-18']);
        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-07-20']);
        Customer::factory()->for($user->salon)->create(['first_visit_at' => null]);

        // 当月の予約: reserved + visited = 2（cancelled は除外）
        $this->reservationAt($user, $menu, '2026-08-21T10:00:00+09:00', ReservationStatus::Reserved, 1000);
        $this->reservationAt($user, $menu, '2026-08-05T10:00:00+09:00', ReservationStatus::Visited, 1000);
        $this->reservationAt($user, $menu, '2026-08-06T10:00:00+09:00', ReservationStatus::Cancelled, 1000);
        // 前月の予約: 1
        $this->reservationAt($user, $menu, '2026-07-06T10:00:00+09:00', ReservationStatus::Visited, 1000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.kpis.new_customers.current', 2);
        $response->assertJsonPath('data.kpis.new_customers.previous', 1);
        $response->assertJsonPath('data.kpis.reservations.current', 2);
        $response->assertJsonPath('data.kpis.reservations.previous', 1);
    }

    public function test_repeat_rate_is_share_of_returning_visitors(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        $repeater = Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-07-10']);
        $newcomer = Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-08-05']);
        $this->reservationAt($user, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Visited, 1000, $repeater);
        $this->reservationAt($user, $menu, '2026-08-05T10:00:00+09:00', ReservationStatus::Visited, 1000, $newcomer);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.kpis.repeat_rate.current', 50.0);
    }

    public function test_sales_trend_returns_six_months_with_zero_fill(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        $this->reservationAt($user, $menu, '2026-05-10T10:00:00+09:00', ReservationStatus::Visited, 40000);
        $this->reservationAt($user, $menu, '2026-08-10T10:00:00+09:00', ReservationStatus::Visited, 20000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonCount(6, 'data.sales_trend');
        $response->assertJsonPath('data.sales_trend.0.month', '2026-03');
        $response->assertJsonPath('data.sales_trend.0.sales', 0);
        $response->assertJsonPath('data.sales_trend.2.month', '2026-05');
        $response->assertJsonPath('data.sales_trend.2.sales', 40000);
        $response->assertJsonPath('data.sales_trend.5.month', '2026-08');
        $response->assertJsonPath('data.sales_trend.5.sales', 20000);
    }

    public function test_today_reservations_lists_jst_today_in_order(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);
        $todayJst = Carbon::today(config('app.salon_timezone'));

        $late = $this->reservationAt($user, $menu, $todayJst->copy()->setTime(23, 30)->toIso8601String(), ReservationStatus::Visited, 1000);
        $early = $this->reservationAt($user, $menu, $todayJst->copy()->setTime(0, 30)->toIso8601String(), ReservationStatus::Reserved, 1000);
        // 前日・翌日・cancelled は含めない
        $this->reservationAt($user, $menu, $todayJst->copy()->subMinutes(30)->toIso8601String(), ReservationStatus::Reserved, 1000);
        $this->reservationAt($user, $menu, $todayJst->copy()->addDay()->setTime(0, 30)->toIso8601String(), ReservationStatus::Reserved, 1000);
        $this->reservationAt($user, $menu, $todayJst->copy()->setTime(10, 0)->toIso8601String(), ReservationStatus::Cancelled, 1000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonCount(2, 'data.today_reservations');
        $response->assertJsonPath('data.today_reservations.0.id', $early->id);
        $response->assertJsonPath('data.today_reservations.1.id', $late->id);
        $response->assertJsonStructure([
            'data' => ['today_reservations' => [['id', 'start_at', 'status', 'customer' => ['id', 'name'], 'menu' => ['id', 'name']]]],
        ]);
    }

    public function test_popular_menus_ranks_visited_menus_of_current_month(): void
    {
        $user = $this->actingAsSalonUser();
        $menuA = Menu::factory()->for($user->salon)->create(['name' => 'フェイシャルA', 'price' => 12000, 'duration_minutes' => 60]);
        $menuB = Menu::factory()->for($user->salon)->create(['name' => 'ヘッドスパB', 'price' => 8000, 'duration_minutes' => 60]);

        $this->reservationAt($user, $menuA, '2026-08-03T10:00:00+09:00', ReservationStatus::Visited, 12000);
        $this->reservationAt($user, $menuA, '2026-08-04T10:00:00+09:00', ReservationStatus::Visited, 12000);
        $this->reservationAt($user, $menuB, '2026-08-05T10:00:00+09:00', ReservationStatus::Visited, 8000);
        // 前月分・cancelled は数えない
        $this->reservationAt($user, $menuB, '2026-07-05T10:00:00+09:00', ReservationStatus::Visited, 8000);
        $this->reservationAt($user, $menuB, '2026-08-06T10:00:00+09:00', ReservationStatus::Cancelled, 8000);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonCount(2, 'data.popular_menus');
        $response->assertJsonPath('data.popular_menus.0.menu_id', $menuA->id);
        $response->assertJsonPath('data.popular_menus.0.name', 'フェイシャルA');
        $response->assertJsonPath('data.popular_menus.0.count', 2);
        $response->assertJsonPath('data.popular_menus.1.menu_id', $menuB->id);
        $response->assertJsonPath('data.popular_menus.1.count', 1);
    }

    public function test_customer_segments_classify_by_visit_history(): void
    {
        $user = $this->actingAsSalonUser();
        $menu = $this->menuFor($user);

        // 休眠: 最終来店が90日超前（基準日 2026-08-20 の90日前 = 2026-05-22）
        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-01-10', 'last_visit_at' => '2026-02-01']);
        // 新規: 初来店が当月
        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-08-05', 'last_visit_at' => '2026-08-05']);
        // リピーター: visited 予約2件・最終来店90日以内
        $repeat = Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-06-01', 'last_visit_at' => '2026-08-01']);
        $this->reservationAt($user, $menu, '2026-06-01T10:00:00+09:00', ReservationStatus::Visited, 1000, $repeat);
        $this->reservationAt($user, $menu, '2026-08-01T10:00:00+09:00', ReservationStatus::Visited, 1000, $repeat);
        // その他: 来店1回・90日以内・当月より前
        Customer::factory()->for($user->salon)->create(['first_visit_at' => '2026-07-15', 'last_visit_at' => '2026-07-15']);
        // 来店歴なしは対象外
        Customer::factory()->for($user->salon)->create(['first_visit_at' => null, 'last_visit_at' => null]);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertJsonPath('data.customer_segments.dormant', 1);
        $response->assertJsonPath('data.customer_segments.new', 1);
        $response->assertJsonPath('data.customer_segments.repeat', 1);
        $response->assertJsonPath('data.customer_segments.other', 1);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    private function menuFor(User $user): Menu
    {
        return Menu::factory()->for($user->salon)->create(['duration_minutes' => 60]);
    }

    private function reservationAt(
        User $user,
        Menu $menu,
        string $startAt,
        ReservationStatus $status,
        int $price,
        ?Customer $customer = null,
    ): Reservation {
        $start = Carbon::parse($startAt)->utc();

        return Reservation::factory()->for($user->salon)->create([
            'customer_id' => ($customer ?? Customer::factory()->for($user->salon)->create())->id,
            'menu_id' => $menu->id,
            'user_id' => $user->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(60),
            'status' => $status,
            'price' => $price,
        ]);
    }
}
