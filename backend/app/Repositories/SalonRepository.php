<?php

namespace App\Repositories;

use App\Models\Salon;

class SalonRepository
{
    /**
     * 公開予約ページ用。無効サロンは公開APIすべてで 404 とするため is_active を条件に含める。
     */
    public function findActiveByBookingSlugOrFail(string $bookingSlug): Salon
    {
        return Salon::where('booking_slug', $bookingSlug)
            ->where('is_active', true)
            ->firstOrFail();
    }

    public function find(int $id): ?Salon
    {
        return Salon::find($id);
    }

    public function findOrFail(int $id): Salon
    {
        return Salon::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Salon $salon, array $data): Salon
    {
        $salon->update($data);

        return $salon->refresh();
    }
}
