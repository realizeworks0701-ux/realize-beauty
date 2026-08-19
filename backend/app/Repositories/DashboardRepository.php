<?php

namespace App\Repositories;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Record;
use App\Models\Reservation;
use Illuminate\Support\Carbon;

class DashboardRepository
{
    public function getSummary(int $salonId): array
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();

        // 「今日の予約」のみサロンのタイムゾーン（JST）の日付境界で判定する（ADR-023）
        $salonToday = Carbon::today(config('app.salon_timezone'));

        return [
            'today_customers' => Record::where('salon_id', $salonId)
                ->whereDate('visited_at', $today)
                ->count(),

            'new_customers' => Customer::where('salon_id', $salonId)
                ->where('first_visit_at', '>=', $startOfMonth)
                ->count(),

            'total_customers' => Customer::where('salon_id', $salonId)->count(),

            'records_this_month' => Record::where('salon_id', $salonId)
                ->where('visited_at', '>=', $startOfMonth)
                ->count(),

            'today_reservations' => Reservation::where('salon_id', $salonId)
                ->whereIn('status', [ReservationStatus::Reserved->value, ReservationStatus::Visited->value])
                ->where('start_at', '>=', $salonToday->copy()->utc())
                ->where('start_at', '<', $salonToday->copy()->addDay()->utc())
                ->count(),

            'recent_customers' => Customer::where('salon_id', $salonId)
                ->orderBy('last_visit_at', 'desc')
                ->limit(5)
                ->get(),

            'recent_records' => Record::where('salon_id', $salonId)
                ->whereHas('customer')
                ->with(['customer', 'user'])
                ->orderBy('visited_at', 'desc')
                ->limit(5)
                ->get(),
        ];
    }
}
