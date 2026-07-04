<?php

namespace App\Services;

use App\Models\Customer;
use App\Repositories\CustomerRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerService
{
    public function __construct(
        private readonly CustomerRepository $customerRepository,
    ) {}

    public function list(int $salonId, array $filters): LengthAwarePaginator
    {
        return $this->customerRepository->paginate($salonId, $filters);
    }

    public function find(int $salonId, int $id): Customer
    {
        return $this->customerRepository->findOrFail($salonId, $id);
    }

    public function create(int $salonId, array $data): Customer
    {
        return $this->customerRepository->create($salonId, $data);
    }

    public function update(int $salonId, int $id, array $data): Customer
    {
        $customer = $this->customerRepository->findOrFail($salonId, $id);

        return $this->customerRepository->update($customer, $data);
    }

    public function delete(int $salonId, int $id): void
    {
        $customer = $this->customerRepository->findOrFail($salonId, $id);
        $this->customerRepository->delete($customer);
    }
}
