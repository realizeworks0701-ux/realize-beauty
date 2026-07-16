<?php

namespace Tests\Concerns;

use App\Models\BusinessHour;
use App\Models\Salon;
use Illuminate\Support\Carbon;

trait CreatesPublicBookingSalon
{
    /**
     * 7曜日分の営業時間を作成する（曜日に依存しないテストにするため）。
     */
    protected function createBusinessHours(Salon $salon, string $openTime = '09:00', string $closeTime = '19:00'): void
    {
        foreach (range(0, 6) as $dayOfWeek) {
            BusinessHour::factory()->for($salon)->create([
                'day_of_week' => $dayOfWeek,
                'is_closed' => false,
                'open_time' => $openTime,
                'close_time' => $closeTime,
            ]);
        }
    }

    protected function closeOn(Salon $salon, string $date): void
    {
        BusinessHour::where('salon_id', $salon->id)
            ->where('day_of_week', $this->salonDate($date)->dayOfWeek)
            ->update(['is_closed' => true]);
    }

    protected function salonDate(string $date): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $date, config('app.salon_timezone'));
    }
}
