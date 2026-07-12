<?php

namespace Tests\Concerns;

use App\Models\Salon;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait CreatesSalonUsers
{
    /**
     * サロンと所属ユーザーを作成し、Sanctum で認証済みにする。
     */
    protected function actingAsSalonUser(?Salon $salon = null, array $attributes = []): User
    {
        $salon ??= Salon::factory()->create();
        $user = User::factory()->for($salon)->create($attributes);

        Sanctum::actingAs($user);

        return $user;
    }
}
