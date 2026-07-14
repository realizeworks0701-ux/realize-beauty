<?php

namespace App\Services;

use App\Models\BusinessHour;
use App\Repositories\BusinessHourRepository;
use Illuminate\Support\Collection;

class BusinessHourService
{
    private const DEFAULT_OPEN_TIME = '09:00';

    private const DEFAULT_CLOSE_TIME = '19:00';

    public function __construct(
        private readonly BusinessHourRepository $businessHourRepository,
    ) {}

    /**
     * 常に7曜日分を返す。DBに行が存在しない曜日はデフォルト値（未保存）で補完する。
     */
    public function list(int $salonId): Collection
    {
        $saved = $this->businessHourRepository->listBySalon($salonId)->keyBy('day_of_week');

        return collect(range(0, 6))->map(
            fn (int $dayOfWeek) => $saved->get($dayOfWeek) ?? new BusinessHour([
                'day_of_week' => $dayOfWeek,
                'is_closed' => false,
                'open_time' => self::DEFAULT_OPEN_TIME,
                'close_time' => self::DEFAULT_CLOSE_TIME,
            ]),
        );
    }

    public function replace(int $salonId, array $businessHours): Collection
    {
        $this->businessHourRepository->replace($salonId, $businessHours);

        return $this->list($salonId);
    }
}
