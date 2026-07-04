<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->where('is_active', true)->first();
    }

    public function updateLastLoginAt(User $user): void
    {
        $user->update(['last_login_at' => now()]);
    }
}
