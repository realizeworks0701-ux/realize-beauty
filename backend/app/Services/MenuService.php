<?php

namespace App\Services;

use App\Models\Menu;
use App\Repositories\MenuRepository;
use Illuminate\Database\Eloquent\Collection;

class MenuService
{
    public function __construct(
        private readonly MenuRepository $menuRepository,
    ) {}

    public function list(int $salonId, array $filters): Collection
    {
        return $this->menuRepository->list($salonId, $filters);
    }

    public function find(int $salonId, int $id): Menu
    {
        return $this->menuRepository->findOrFail($salonId, $id);
    }

    public function create(int $salonId, array $data): Menu
    {
        if (! isset($data['display_order'])) {
            $data['display_order'] = ($this->menuRepository->maxDisplayOrder($salonId) ?? 0) + 1;
        }

        return $this->menuRepository->create($salonId, $data);
    }

    public function update(int $salonId, int $id, array $data): Menu
    {
        $menu = $this->menuRepository->findOrFail($salonId, $id);

        return $this->menuRepository->update($menu, $data);
    }

    public function delete(int $salonId, int $id): void
    {
        $menu = $this->menuRepository->findOrFail($salonId, $id);
        $this->menuRepository->delete($menu);
    }
}
