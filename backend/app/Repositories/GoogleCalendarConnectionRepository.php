<?php

namespace App\Repositories;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Models\GoogleCalendarConnection;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class GoogleCalendarConnectionRepository
{
    /**
     * per_staff モードのスタッフ接続。
     */
    public function findBySalonAndUser(int $salonId, int $userId): ?GoogleCalendarConnection
    {
        return GoogleCalendarConnection::where('salon_id', $salonId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * shared モードのサロン共有接続（user_id = null）。
     */
    public function findSharedBySalon(int $salonId): ?GoogleCalendarConnection
    {
        return GoogleCalendarConnection::where('salon_id', $salonId)
            ->whereNull('user_id')
            ->first();
    }

    /**
     * 同期ジョブ・定期コマンド用（サロン文脈を持たない接続IDからの取得）。
     */
    public function find(int $id): ?GoogleCalendarConnection
    {
        return GoogleCalendarConnection::find($id);
    }

    public function findBySalonAndId(int $salonId, int $id): ?GoogleCalendarConnection
    {
        return GoogleCalendarConnection::where('salon_id', $salonId)
            ->whereKey($id)
            ->first();
    }

    public function findBySalonAndIdOrFail(int $salonId, int $id): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::where('salon_id', $salonId)
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * webhook の X-Goog-Channel-ID からの逆引き（channel_id は Unique）。
     * 通知はサロン文脈を持たないため、ここだけは salon スコープを張れない。
     */
    public function findByChannelId(string $channelId): ?GoogleCalendarConnection
    {
        return GoogleCalendarConnection::where('channel_id', $channelId)->first();
    }

    /**
     * @return Collection<int, GoogleCalendarConnection>
     */
    public function listBySalon(int $salonId): Collection
    {
        return GoogleCalendarConnection::where('salon_id', $salonId)
            ->with('user')
            ->orderBy('id')
            ->get();
    }

    /**
     * 定期コマンド用（全サロン横断）。
     *
     * @return Collection<int, GoogleCalendarConnection>
     */
    public function listActive(): Collection
    {
        return GoogleCalendarConnection::where('status', GoogleCalendarConnectionStatus::Active->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * 期限が迫った watch チャネル（張り直し対象）。
     *
     * @return Collection<int, GoogleCalendarConnection>
     */
    public function listExpiringChannels(Carbon $before): Collection
    {
        return GoogleCalendarConnection::where('status', GoogleCalendarConnectionStatus::Active->value)
            ->whereNotNull('channel_id')
            ->where('channel_expires_at', '<', $before->copy()->utc())
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $salonId, array $data): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::create(array_merge($data, [
            'salon_id' => $salonId,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GoogleCalendarConnection $connection, array $data): GoogleCalendarConnection
    {
        $connection->update($data);

        return $connection->refresh();
    }

    /**
     * 全ページ適用・コミット後にのみ呼ぶこと（先に保存すると取りこぼしが恒久化する）。
     */
    public function updateSyncToken(GoogleCalendarConnection $connection, ?string $syncToken): GoogleCalendarConnection
    {
        return $this->update($connection, [
            'sync_token' => $syncToken,
            'last_synced_at' => now(),
        ]);
    }

    public function clearSyncToken(GoogleCalendarConnection $connection): GoogleCalendarConnection
    {
        return $this->update($connection, ['sync_token' => null]);
    }

    public function markNeedsReconnect(GoogleCalendarConnection $connection): GoogleCalendarConnection
    {
        return $this->update($connection, [
            'status' => GoogleCalendarConnectionStatus::NeedsReconnect,
        ]);
    }

    /**
     * 解除は物理削除（busy ブロックは FK の cascade delete で消える）。
     */
    public function delete(GoogleCalendarConnection $connection): void
    {
        $connection->delete();
    }
}
