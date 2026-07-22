<?php

namespace App\Console\Commands;

use App\Jobs\SyncGoogleCalendarJob;
use App\Repositories\GoogleCalendarConnectionRepository;
use Illuminate\Console\Command;

/**
 * 同期窓を当日基準へ前進させる日次コマンド。
 * syncToken には初回全同期時の timeMin/timeMax が固定的に紐づき増分同期では窓を動かせないため、
 * syncToken を破棄した全同期を投入して窓を張り直す（ADR-025 §5 / Business Rules 13）。
 */
class RefreshGoogleCalendarSync extends Command
{
    protected $signature = 'google-calendar:refresh-sync';

    protected $description = 'syncToken を破棄した全同期で Google カレンダーの同期窓を当日基準へ前進させる';

    public function handle(GoogleCalendarConnectionRepository $connectionRepository): int
    {
        $connections = $connectionRepository->listActive();

        foreach ($connections as $connection) {
            // syncToken を破棄 → 受信同期ジョブが全同期（照合削除つき）を行う
            $connectionRepository->clearSyncToken($connection);
            SyncGoogleCalendarJob::dispatch($connection->id);
        }

        $this->info("同期窓前進の全同期を {$connections->count()} 件投入しました。");

        return self::SUCCESS;
    }
}
