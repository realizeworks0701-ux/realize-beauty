<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Salon;
use App\Models\Subscription;
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
        {--email= : ログイン用メールアドレス}
        {--plan=lite : 初期プラン（lite/standard/pro）。Checkout 完了後は Stripe 側の内容で上書きされる}';

    protected $description = '初期オーナーユーザーを作成する（パスワードは対話入力。本番の初期投入用）';

    /**
     * 端末やシェル経由で多バイト文字が分断されると、DB挿入時に
     * SQLSTATE[22021] invalid byte sequence となり原因が分かりにくい。手前で弾く。
     *
     * @param  array<string, string|null>  $input
     * @return list<string>
     */
    private function fieldsWithBrokenEncoding(array $input): array
    {
        return array_keys(array_filter(
            $input,
            fn (?string $value) => $value !== null && ! mb_check_encoding($value, 'UTF-8'),
        ));
    }

    public function handle(): int
    {
        $input = [
            'salon' => $this->option('salon') ?: $this->ask('サロン名'),
            'phone' => $this->option('phone') ?: $this->ask('サロンの電話番号'),
            'postal_code' => $this->option('postal-code') ?: $this->ask('サロンの郵便番号'),
            'address' => $this->option('address') ?: $this->ask('サロンの住所'),
            'name' => $this->option('name') ?: $this->ask('オーナーの氏名'),
            'email' => $this->option('email') ?: $this->ask('ログイン用メールアドレス'),
        ];

        if ($broken = $this->fieldsWithBrokenEncoding($input)) {
            $this->error('入力が UTF-8 として壊れています: '.implode(', ', $broken));
            $this->line('対話入力で多バイト文字が分断される環境（Render の Shell など）がある。');
            $this->line('オプションで渡し直すこと: php artisan salon:create-owner --salon=... --address=... --name=...');

            return self::FAILURE;
        }

        $input['password'] = $this->secret('パスワード（12文字以上）');

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

        $plan = SubscriptionPlan::tryFrom((string) $this->option('plan'));

        if ($plan === null) {
            $this->error('--plan には lite / standard / pro のいずれかを指定してください。');

            return self::FAILURE;
        }

        $existing = Salon::where('name', $input['salon'])->first();
        if ($existing) {
            $this->warn("同名のサロンが既にあるため再利用する（salon_id={$existing->id}）。電話番号・住所は既存の値のまま。");
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

        // 契約が無いサロンは全機能が 403 になるため、プロビジョニング時点で契約行を用意する（ADR-029）。
        // Stripe とはまだ紐づかない。Checkout 完了時に webhook が stripe_* とプランを上書きする。
        $subscription = Subscription::firstOrCreate(
            ['salon_id' => $salon->id],
            ['plan' => $plan, 'status' => SubscriptionStatus::Active],
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
        $this->info("契約プラン: {$subscription->plan->label()}（{$subscription->status->label()}）。");

        return self::SUCCESS;
    }
}
