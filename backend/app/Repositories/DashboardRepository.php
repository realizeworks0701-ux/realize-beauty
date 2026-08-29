<?php

namespace App\Repositories;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class DashboardRepository
{
    /** 休眠とみなす最終来店からの経過日数 */
    private const DORMANT_DAYS = 90;

    /** 人気メニューの表示件数 */
    private const POPULAR_MENU_LIMIT = 5;

    /** 売上推移の月数（当月含む） */
    private const TREND_MONTHS = 6;

    public function getSummary(int $salonId): array
    {
        $timezone = config('app.salon_timezone');
        $monthStart = Carbon::now($timezone)->startOfMonth();
        $prevMonthStart = $monthStart->copy()->subMonth();
        $nextMonthStart = $monthStart->copy()->addMonth();

        return [
            'kpis' => [
                'new_customers' => [
                    'current' => $this->countNewCustomers($salonId, $monthStart, $nextMonthStart),
                    'previous' => $this->countNewCustomers($salonId, $prevMonthStart, $monthStart),
                ],
                'reservations' => [
                    'current' => $this->countReservations($salonId, $monthStart, $nextMonthStart),
                    'previous' => $this->countReservations($salonId, $prevMonthStart, $monthStart),
                ],
                'sales' => [
                    'current' => $this->sumSales($salonId, $monthStart, $nextMonthStart),
                    'previous' => $this->sumSales($salonId, $prevMonthStart, $monthStart),
                ],
                'repeat_rate' => [
                    'current' => $this->repeatRate($salonId, $monthStart, $nextMonthStart),
                    'previous' => $this->repeatRate($salonId, $prevMonthStart, $monthStart),
                ],
            ],
            'sales_trend' => $this->salesTrend($salonId, $monthStart),
            'today_reservations' => $this->todayReservations($salonId),
            'popular_menus' => $this->popularMenus($salonId, $monthStart, $nextMonthStart),
            'customer_segments' => $this->customerSegments($salonId, $monthStart),
        ];
    }

    private function countNewCustomers(int $salonId, Carbon $from, Carbon $toExclusive): int
    {
        return Customer::where('salon_id', $salonId)
            ->where('first_visit_at', '>=', $from->toDateString())
            ->where('first_visit_at', '<', $toExclusive->toDateString())
            ->count();
    }

    private function countReservations(int $salonId, Carbon $from, Carbon $toExclusive): int
    {
        return Reservation::where('salon_id', $salonId)
            ->whereIn('status', [ReservationStatus::Reserved->value, ReservationStatus::Visited->value])
            ->where('start_at', '>=', $from->copy()->utc())
            ->where('start_at', '<', $toExclusive->copy()->utc())
            ->count();
    }

    private function sumSales(int $salonId, Carbon $from, Carbon $toExclusive): int
    {
        return (int) Reservation::where('salon_id', $salonId)
            ->where('status', ReservationStatus::Visited->value)
            ->where('start_at', '>=', $from->copy()->utc())
            ->where('start_at', '<', $toExclusive->copy()->utc())
            ->sum('price');
    }

    /**
     * 期間内に来店した顧客のうち、期間開始より前に初来店していた顧客の割合（%・小数1桁）。
     * 来店者がいない期間は 0 を返す。
     */
    private function repeatRate(int $salonId, Carbon $from, Carbon $toExclusive): float
    {
        $visitorIds = Reservation::where('salon_id', $salonId)
            ->where('status', ReservationStatus::Visited->value)
            ->where('start_at', '>=', $from->copy()->utc())
            ->where('start_at', '<', $toExclusive->copy()->utc())
            ->distinct()
            ->pluck('customer_id');

        if ($visitorIds->isEmpty()) {
            return 0.0;
        }

        $repeaters = Customer::where('salon_id', $salonId)
            ->whereIn('id', $visitorIds)
            ->where('first_visit_at', '<', $from->toDateString())
            ->count();

        return round($repeaters / $visitorIds->count() * 100, 1);
    }

    private function salesTrend(int $salonId, Carbon $monthStart): array
    {
        $trendStart = $monthStart->copy()->subMonths(self::TREND_MONTHS - 1);
        $nextMonthStart = $monthStart->copy()->addMonth();

        $sales = Reservation::where('salon_id', $salonId)
            ->where('status', ReservationStatus::Visited->value)
            ->where('start_at', '>=', $trendStart->copy()->utc())
            ->where('start_at', '<', $nextMonthStart->copy()->utc())
            ->selectRaw(
                "to_char(start_at at time zone ?, 'YYYY-MM') as month, coalesce(sum(price), 0) as sales",
                [config('app.salon_timezone')],
            )
            ->groupBy('month')
            ->pluck('sales', 'month');

        return collect(range(0, self::TREND_MONTHS - 1))
            ->map(function (int $offset) use ($trendStart, $sales) {
                $month = $trendStart->copy()->addMonths($offset)->format('Y-m');

                return ['month' => $month, 'sales' => (int) ($sales[$month] ?? 0)];
            })
            ->all();
    }

    private function todayReservations(int $salonId): Collection
    {
        $salonToday = Carbon::today(config('app.salon_timezone'));

        return Reservation::where('salon_id', $salonId)
            ->whereIn('status', [ReservationStatus::Reserved->value, ReservationStatus::Visited->value])
            ->where('start_at', '>=', $salonToday->copy()->utc())
            ->where('start_at', '<', $salonToday->copy()->addDay()->utc())
            ->with(['customer', 'menu', 'user'])
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();
    }

    private function popularMenus(int $salonId, Carbon $from, Carbon $toExclusive): array
    {
        $counts = Reservation::where('salon_id', $salonId)
            ->where('status', ReservationStatus::Visited->value)
            ->where('start_at', '>=', $from->copy()->utc())
            ->where('start_at', '<', $toExclusive->copy()->utc())
            ->selectRaw('menu_id, count(*) as reservation_count')
            ->groupBy('menu_id')
            ->orderByDesc('reservation_count')
            ->orderBy('menu_id')
            ->limit(self::POPULAR_MENU_LIMIT)
            ->get();

        $menus = Menu::withTrashed()
            ->whereIn('id', $counts->pluck('menu_id'))
            ->get()
            ->keyBy('id');

        return $counts
            ->map(function ($row) use ($menus) {
                $menu = $menus->get($row->menu_id);

                return [
                    'menu_id' => $row->menu_id,
                    'name' => $menu?->name ?? '不明なメニュー',
                    'price' => $menu?->price,
                    'count' => (int) $row->reservation_count,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 来店歴のある顧客を 休眠 → 新規 → リピーター → その他 の優先順で分類する（ADR-026）。
     */
    private function customerSegments(int $salonId, Carbon $monthStart): array
    {
        $dormantBefore = Carbon::today(config('app.salon_timezone'))
            ->subDays(self::DORMANT_DAYS)
            ->toDateString();
        $monthStartDate = $monthStart->toDateString();

        $base = fn () => Customer::where('salon_id', $salonId)->whereNotNull('first_visit_at');

        $total = $base()->count();
        $dormant = $base()->where('last_visit_at', '<', $dormantBefore)->count();
        $new = $base()
            ->where('last_visit_at', '>=', $dormantBefore)
            ->where('first_visit_at', '>=', $monthStartDate)
            ->count();
        $repeat = $base()
            ->where('last_visit_at', '>=', $dormantBefore)
            ->where('first_visit_at', '<', $monthStartDate)
            ->whereHas(
                'reservations',
                fn ($query) => $query->where('status', ReservationStatus::Visited->value),
                '>=',
                2,
            )
            ->count();

        return [
            'new' => $new,
            'repeat' => $repeat,
            'dormant' => $dormant,
            'other' => $total - $dormant - $new - $repeat,
        ];
    }
}
