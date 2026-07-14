<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->where('is_active', true)->first();
    }

    public function listActiveBySalon(int $salonId): Collection
    {
        return User::where('salon_id', $salonId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    public function findActiveBySalon(int $salonId, int $id): ?User
    {
        return User::where('salon_id', $salonId)
            ->where('is_active', true)
            ->find($id);
    }

    public function updateLastLoginAt(User $user): void
    {
        $user->update(['last_login_at' => now()]);
    }
}
