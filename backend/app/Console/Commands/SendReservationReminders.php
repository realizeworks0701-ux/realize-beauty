<?php

namespace App\Console\Commands;

use App\Jobs\SendReservationReminderJob;
use App\Repositories\ReservationRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendReservationReminders extends Command
{
    protected $signature = 'reservations:send-reminders';

    protected $description = '翌日（JST）の予約に対する前日リマインダーのLINE push ジョブを投入する';

    public function handle(ReservationRepository $reservationRepository): int
    {
        $timezone = config('app.salon_timezone');
        $from = Carbon::tomorrow($timezone);
        $toExclusive = $from->copy()->addDay();

        $reservations = $reservationRepository->listForReminder(
            $from->copy()->utc(),
            $toExclusive->copy()->utc(),
        );

        foreach ($reservations as $reservation) {
            SendReservationReminderJob::dispatch($reservation->id);
        }

        $this->info("リマインダージョブを {$reservations->count()} 件投入しました。");

        return self::SUCCESS;
    }
}
