<?php

use App\Enums\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 課金導入前から存在するサロンに契約行を投入する（ADR-029）。
     *
     * 既存サロンは全機能を使える前提で運用されてきたため、機能を取り上げないよう
     * Pro / active を割り当てる。Stripe 側の Customer・Subscription はまだ無いため
     * stripe_* は null のままとし、初回の Checkout 完了時に紐づける。
     * 割当プランは BILLING_BACKFILL_PLAN で上書きできる。
     */
    public function up(): void
    {
        // 不正な値をそのまま入れると、以後この行を読むすべてのリクエストが
        // enum キャストの ValueError で 500 になる。ここで弾く。
        $plan = SubscriptionPlan::tryFrom((string) env('BILLING_BACKFILL_PLAN', 'pro'))
            ?? throw new InvalidArgumentException(
                'BILLING_BACKFILL_PLAN には lite / standard / pro のいずれかを指定してください。',
            );
        $now = now();

        $salonIds = DB::table('salons')
            ->whereNotIn('id', fn ($query) => $query->select('salon_id')->from('subscriptions'))
            ->pluck('id');

        foreach ($salonIds->chunk(500) as $chunk) {
            DB::table('subscriptions')->insert($chunk->map(fn (int $salonId) => [
                'salon_id' => $salonId,
                'plan' => $plan->value,
                'status' => 'active',
                'cancel_at_period_end' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }
    }

    /**
     * Stripe と未接続の（バックフィルで作られた）行だけを取り消す。
     * Checkout 済みの契約は Stripe 側に実体があるため消さない。
     */
    public function down(): void
    {
        DB::table('subscriptions')
            ->whereNull('stripe_customer_id')
            ->whereNull('stripe_subscription_id')
            ->delete();
    }
};
