<?php

namespace App\Repositories;

use App\Models\Menu;
use Illuminate\Database\Eloquent\Collection;

class MenuRepository
{
    public function list(int $salonId, array $filters): Collection
    {
        return Menu::where('salon_id', $salonId)
            ->when(
                isset($filters['is_active']),
                fn ($query) => $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)),
            )
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    public function findOrFail(int $salonId, int $id): Menu
    {
        return Menu::where('salon_id', $salonId)->findOrFail($id);
    }

    public function findActive(int $salonId, int $id): ?Menu
    {
        return Menu::where('salon_id', $salonId)
            ->where('is_active', true)
            ->find($id);
    }

    public function maxDisplayOrder(int $salonId): ?int
    {
        return Menu::where('salon_id', $salonId)->max('display_order');
    }

    public function create(int $salonId, array $data): Menu
    {
        return Menu::create(array_merge($data, [
            'salon_id' => $salonId,
        ]));
    }

    public function update(Menu $menu, array $data): Menu
    {
        $menu->update($data);

        return $menu->fresh();
    }

    public function delete(Menu $menu): void
    {
        $menu->delete();
    }
}
