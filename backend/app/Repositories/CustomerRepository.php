<?php

namespace App\Repositories;

use App\Models\Customer;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerRepository
{
    public function paginate(int $salonId, array $filters): LengthAwarePaginator
    {
        $query = Customer::where('salon_id', $salonId);

        if (! empty($filters['keyword'])) {
            $keyword = '%' . $filters['keyword'] . '%';

            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', $keyword)
                    ->orWhere('kana', 'like', $keyword)
                    ->orWhere('phone', 'like', $keyword)
                    ->orWhere('email', 'like', $keyword);
            });
        }

        if (isset($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }

        if (! empty($filters['visited_after'])) {
            $query->where('last_visit_at', '>=', $filters['visited_after']);
        }

        if (! empty($filters['visited_before'])) {
            $query->where('last_visit_at', '<=', $filters['visited_before']);
        }

        $sort = $filters['sort'] ?? '-id';

        match ($sort) {
            'id' => $query->orderBy('id'),
            '-id' => $query->orderByDesc('id'),

            'name' => $query->orderBy('name'),
            '-name' => $query->orderByDesc('name'),

            'last_visit_at' => $query->orderBy('last_visit_at'),
            '-last_visit_at' => $query->orderByDesc('last_visit_at'),

            default => $query->orderByDesc('id'),
        };

        $perPage = $filters['per_page'] ?? 20;

        return $query->paginate($perPage);
    }

    public function findOrFail(int $salonId, int $id): Customer
    {
        return Customer::where('salon_id', $salonId)->findOrFail($id);
    }

    public function create(int $salonId, array $data): Customer
    {
        return Customer::create(array_merge($data, [
            'salon_id' => $salonId,
        ]));
    }

    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer->fresh();
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }
}
