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

    public function test_index_returns_summary_structure(): void
    {
        $this->actingAsSalonUser();

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'today_customers',
                'new_customers',
                'total_customers',
                'records_this_month',
                'today_reservations',
                'recent_customers',
                'recent_records',
            ],
        ]);
    }

    public function test_today_reservations_counts_jst_today_reserved_and_visited_only(): void
    {
        $user = $this->actingAsSalonUser();
        $todayJst = Carbon::today(config('app.salon_timezone'));

        // JST当日の境界内（0:30 と 23:30）
        $this->reservationAt($user, $todayJst->copy()->setTime(0, 30), ReservationStatus::Reserved);
        $this->reservationAt($user, $todayJst->copy()->setTime(23, 30), ReservationStatus::Visited);

        // JST前日・翌日は含めない
        $this->reservationAt($user, $todayJst->copy()->subMinutes(30), ReservationStatus::Reserved);
        $this->reservationAt($user, $todayJst->copy()->addDay()->setTime(0, 30), ReservationStatus::Reserved);

        // cancelled / no_show は集計から除外する
        $this->reservationAt($user, $todayJst->copy()->setTime(10, 0), ReservationStatus::Cancelled);
        $this->reservationAt($user, $todayJst->copy()->setTime(12, 0), ReservationStatus::NoShow);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('data.today_reservations', 2);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    private function reservationAt(User $user, Carbon $startAt, ReservationStatus $status): Reservation
    {
        $start = $startAt->copy()->utc();

        return Reservation::factory()->for($user->salon)->create([
            'customer_id' => Customer::factory()->for($user->salon),
            'menu_id' => Menu::factory()->for($user->salon),
            'user_id' => $user->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(60),
            'status' => $status,
        ]);
    }
}
