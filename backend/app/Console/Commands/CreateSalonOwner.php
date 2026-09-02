<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSalonOwner extends Command
{
    protected $signature = 'salon:create-owner
        {--salon= : サロン名（同名があれば再利用する）}
        {--phone= : サロンの電話番号}
        {--postal-code= : サロンの郵便番号}
        {--address= : サロンの住所}
        {--name= : オーナーの氏名}
        {--email= : ログイン用メールアドレス}';

    protected $description = '初期オーナーユーザーを作成する（パスワードは対話入力。本番の初期投入用）';

    public function handle(): int
    {
        $input = [
            'salon' => $this->option('salon') ?: $this->ask('サロン名'),
            'phone' => $this->option('phone') ?: $this->ask('サロンの電話番号'),
            'postal_code' => $this->option('postal-code') ?: $this->ask('サロンの郵便番号'),
            'address' => $this->option('address') ?: $this->ask('サロンの住所'),
            'name' => $this->option('name') ?: $this->ask('オーナーの氏名'),
            'email' => $this->option('email') ?: $this->ask('ログイン用メールアドレス'),
            'password' => $this->secret('パスワード（12文字以上）'),
        ];

        $validator = Validator::make($input, [
            'salon' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if ($input['password'] !== $this->secret('パスワード（確認のため再入力）')) {
            $this->error('パスワードが一致しません。');

            return self::FAILURE;
        }

        $salon = Salon::firstOrCreate(
            ['name' => $input['salon']],
            [
                'phone' => $input['phone'],
                'postal_code' => $input['postal_code'],
                'address' => $input['address'],
                'is_active' => true,
            ],
        );

        $user = User::create([
            'salon_id' => $salon->id,
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'role' => Role::Owner,
            'is_active' => true,
        ]);

        $this->info("オーナーユーザーを作成しました（salon_id={$salon->id}, user_id={$user->id}）。");

        return self::SUCCESS;
    }
}
