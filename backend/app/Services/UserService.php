<?php

namespace App\Services;

use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Collection;

class UserService
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    public function listStaff(int $salonId): Collection
    {
        return $this->userRepository->listActiveBySalon($salonId);
    }
}
