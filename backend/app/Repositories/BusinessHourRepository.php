<?php

namespace App\Repositories;

use App\Models\BusinessHour;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BusinessHourRepository
{
    public function listBySalon(int $salonId): Collection
    {
        return BusinessHour::where('salon_id', $salonId)
            ->orderBy('day_of_week')
            ->get();
    }

    public function replace(int $salonId, array $businessHours): void
    {
        DB::transaction(function () use ($salonId, $businessHours) {
            BusinessHour::where('salon_id', $salonId)->delete();

            foreach ($businessHours as $businessHour) {
                BusinessHour::create([
                    'salon_id' => $salonId,
                    'day_of_week' => $businessHour['day_of_week'],
                    'is_closed' => $businessHour['is_closed'],
                    'open_time' => $businessHour['open_time'],
                    'close_time' => $businessHour['close_time'],
                ]);
            }
        });
    }
}
