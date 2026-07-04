<?php

namespace App\Repositories;

use App\Models\Customer;
use App\Models\Record;
use Illuminate\Support\Carbon;

class DashboardRepository
{
    public function getSummary(int $salonId): array
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();

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

            'recent_customers' => Customer::where('salon_id', $salonId)
                ->orderBy('last_visit_at', 'desc')
                ->limit(5)
                ->get(),

            'recent_records' => Record::where('salon_id', $salonId)
                ->with(['customer', 'user'])
                ->orderBy('visited_at', 'desc')
                ->limit(5)
                ->get(),
        ];
    }
}
