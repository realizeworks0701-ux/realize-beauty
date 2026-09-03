<?php

namespace App\Services\Billing;

use App\Enums\Role;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Salon;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\SubscriptionRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 契約のビジネスロジック（ADR-029）。
 *
 * 決済・請求の Source of Truth は Stripe に置き、ここでは機能制御に必要な項目だけを
 * DB へ同期する。請求金額の計算・日割りは一切自前で行わず Stripe に委ねる。
 */
class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly StripeClient $stripeClient,
        private readonly EntitlementService $entitlements,
    ) {}

    public function findForSalon(int $salonId): ?Subscription
    {
        return $this->subscriptionRepository->findBySalonId($salonId);
    }

    /**
     * Checkout セッションを作成し、リダイレクト先URLを返す。
     *
     * 受け取るのはアプリの plan key のみ。Price ID はここで config から引くため、
     * クライアントが指定した値が Stripe へ渡ることはない。
     */
    public function startCheckout(User $user, SubscriptionPlan $plan): string
    {
        $this->assertCanManageBilling($user);
        $priceId = $this->priceIdOrFail($plan);
        $salonId = (int) $user->salon_id;
        $subscription = $this->subscriptionRepository->findBySalonId($salonId);

        if ($subscription?->status->grantsAccess() && $subscription->stripe_subscription_id !== null) {
            throw ValidationException::withMessages([
                'plan' => ['すでに契約中です。プラン変更をご利用ください。'],
            ]);
        }

        $this->assertNoLiveSubscriptionOnStripe($subscription);

        $session = $this->stripeClient->createCheckoutSession(
            priceId: $priceId,
            successUrl: $this->returnUrl('success').'&session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: $this->returnUrl('cancel'),
            customerId: $subscription?->stripe_customer_id,
            customerEmail: $subscription?->stripe_customer_id === null ? $user->email : null,
            metadata: ['salon_id' => (string) $salonId, 'plan' => $plan->value],
        );

        $url = $session['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new StripeApiException('Stripe Checkout セッションの URL を取得できませんでした。');
        }

        return $url;
    }

    /**
     * Checkout から戻ってきた直後に、その結果をその場で取り込む。
     *
     * Webhook の到着を待つ間はアプリDBが「未契約」のままで、画面には購入ボタンが並んだままになる。
     * そこで2本目を契約されると二重課金になるため、戻り先の URL に含まれる session_id で
     * Stripe から結果を取り直し、窓を閉じる（Webhook が来れば冪等に同じ結果へ収束する）。
     *
     * session_id は URL 経由で渡ってくるので、必ず自サロンのセッションかを確かめる。
     */
    public function syncCheckoutSession(User $user, string $sessionId): ?Subscription
    {
        $this->assertCanManageBilling($user);

        $session = $this->stripeClient->retrieveCheckoutSession($sessionId);

        $salonId = $this->salonIdFromMetadata($session)
            ?? $this->intOrNull($session['client_reference_id'] ?? null);

        if ($salonId !== (int) $user->salon_id) {
            throw new AuthorizationException('この決済セッションはご自身のサロンのものではありません。');
        }

        return $this->applyCheckoutSession($session);
    }

    /**
     * Stripe 側にすでに有効な契約が無いことを確かめる。
     *
     * Checkout 完了から Webhook 到着までの数秒はアプリDBが「未契約」のままなので、
     * DBだけを見ていると同じサロンが2本目の Checkout を通せてしまい、
     * アプリからは見えないまま二重に課金される。Stripe を直接見てこの窓を塞ぐ。
     *
     * 見つかった場合はその契約をDBへ取り込んでから中断する。ユーザーが画面を
     * 再読み込みすれば、エラーではなく正しい契約状態が見える。
     */
    private function assertNoLiveSubscriptionOnStripe(?Subscription $subscription): void
    {
        if ($subscription?->stripe_customer_id === null) {
            return;
        }

        foreach ($this->stripeClient->listSubscriptions($subscription->stripe_customer_id) as $candidate) {
            $status = SubscriptionStatus::tryFrom((string) ($candidate['status'] ?? ''));

            if ($status?->grantsAccess() !== true) {
                continue;
            }

            $this->syncFromStripe($candidate, asOf: Carbon::now()->utc());

            throw ValidationException::withMessages([
                'plan' => ['すでにご契約が有効です。画面を再読み込みしてからお試しください。'],
            ]);
        }
    }

    /**
     * Customer Portal のURLを返す。支払い方法・請求履歴は Stripe の画面で扱う。
     */
    public function createPortalSession(User $user): string
    {
        $this->assertCanManageBilling($user);
        $subscription = $this->requireStripeCustomer((int) $user->salon_id);

        $session = $this->stripeClient->createBillingPortalSession(
            $subscription->stripe_customer_id,
            $this->returnUrl('portal'),
        );

        $url = $session['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new StripeApiException('Stripe カスタマーポータルの URL を取得できませんでした。');
        }

        return $url;
    }

    /**
     * プランを変更する。差額の精算は Stripe の日割り（create_prorations）に委ねる。
     * アップグレードは即時反映、ダウングレードの差額は次回請求へクレジットとして繰り越される。
     */
    public function changePlan(User $user, SubscriptionPlan $plan): Subscription
    {
        $this->assertCanManageBilling($user);
        $subscription = $this->requireStripeSubscription((int) $user->salon_id);
        $priceId = $this->priceIdOrFail($plan);

        if ($subscription->plan === $plan) {
            throw ValidationException::withMessages([
                'plan' => ['すでにこのプランをご契約中です。'],
            ]);
        }

        $current = $this->stripeClient->retrieveSubscription($subscription->stripe_subscription_id);
        $itemId = $current['items']['data'][0]['id'] ?? null;

        if (! is_string($itemId)) {
            throw new StripeApiException('Stripe サブスクリプションの明細を取得できませんでした。');
        }

        $updated = $this->stripeClient->updateSubscriptionPrice(
            $subscription->stripe_subscription_id,
            $itemId,
            $priceId,
        );

        // Stripe の応答はこの瞬間の正本。鮮度を進めておかないと、
        // 直前に発生していた webhook が後から届いてこの操作を巻き戻す。
        return $this->syncFromStripe($updated, asOf: Carbon::now()->utc()) ?? $subscription;
    }

    /**
     * 解約を申請する。即時停止ではなく期間終了時に停止する（データも削除しない）。
     */
    public function cancel(User $user): Subscription
    {
        $this->assertCanManageBilling($user);
        $subscription = $this->requireStripeSubscription((int) $user->salon_id);

        if ($subscription->cancel_at_period_end) {
            throw ValidationException::withMessages([
                'subscription' => ['すでに解約を申請済みです。'],
            ]);
        }

        $updated = $this->stripeClient->setCancelAtPeriodEnd($subscription->stripe_subscription_id, true);

        // Stripe の応答はこの瞬間の正本。鮮度を進めておかないと、
        // 直前に発生していた webhook が後から届いてこの操作を巻き戻す。
        return $this->syncFromStripe($updated, asOf: Carbon::now()->utc()) ?? $subscription;
    }

    /**
     * 解約申請を取り消して契約を継続する。
     */
    public function resume(User $user): Subscription
    {
        $this->assertCanManageBilling($user);
        $subscription = $this->requireStripeSubscription((int) $user->salon_id);

        if (! $subscription->cancel_at_period_end) {
            throw ValidationException::withMessages([
                'subscription' => ['解約は申請されていません。'],
            ]);
        }

        $updated = $this->stripeClient->setCancelAtPeriodEnd($subscription->stripe_subscription_id, false);

        // Stripe の応答はこの瞬間の正本。鮮度を進めておかないと、
        // 直前に発生していた webhook が後から届いてこの操作を巻き戻す。
        return $this->syncFromStripe($updated, asOf: Carbon::now()->utc()) ?? $subscription;
    }

    /**
     * Stripe の subscription オブジェクトを DB へ反映する。
     *
     * Webhook・プラン変更・解約のすべてがこの1本を通る。payload をそのまま信用せず、
     * 必要な項目だけを型を確かめて取り出し、サロンは metadata もしくは
     * stripe_customer_id / stripe_subscription_id の突合で解決する。
     *
     * @param  array<string, mixed>  $stripeSubscription
     */
    public function syncFromStripe(
        array $stripeSubscription,
        ?string $stripeEventId = null,
        ?Carbon $asOf = null,
    ): ?Subscription {
        $stripeSubscriptionId = $this->stringOrNull($stripeSubscription['id'] ?? null);
        $stripeCustomerId = $this->stringOrNull($stripeSubscription['customer'] ?? null);

        if ($stripeSubscriptionId === null) {
            return null;
        }

        $subscription = $this->resolveSubscription($stripeSubscription, $stripeSubscriptionId, $stripeCustomerId);

        if ($subscription === null) {
            return null;
        }

        // Stripe は Webhook の順序を保証しない。いま持っている内容より古い情報は捨てる
        // （解約後に遅れて届いた updated が契約を復活させないようにする）。
        if ($asOf !== null
            && $subscription->last_stripe_event_at !== null
            && $asOf->lt($subscription->last_stripe_event_at)) {
            return $subscription;
        }

        $status = SubscriptionStatus::tryFrom((string) ($stripeSubscription['status'] ?? ''));
        $item = $stripeSubscription['items']['data'][0] ?? [];
        $priceId = $this->stringOrNull($item['price']['id'] ?? null);
        $plan = $priceId !== null ? SubscriptionPlan::fromStripePriceId($priceId) : null;

        $before = [
            'plan' => $subscription->plan,
            'status' => $subscription->status,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'stripe_subscription_id' => $subscription->stripe_subscription_id,
        ];

        $attributes = array_filter([
            'stripe_subscription_id' => $stripeSubscriptionId,
            'stripe_customer_id' => $stripeCustomerId,
            'stripe_price_id' => $priceId,
            'plan' => $plan,
            'status' => $status,
        ], fn ($value) => $value !== null);

        $attributes['cancel_at_period_end'] = (bool) ($stripeSubscription['cancel_at_period_end'] ?? false);
        $attributes['current_period_start'] = $this->timestamp($stripeSubscription, $item, 'current_period_start');
        $attributes['current_period_end'] = $this->timestamp($stripeSubscription, $item, 'current_period_end');
        $attributes['canceled_at'] = $this->timestampAt($stripeSubscription['canceled_at'] ?? null);
        $attributes['ended_at'] = $this->timestampAt($stripeSubscription['ended_at'] ?? null);
        $attributes['trial_ends_at'] = $this->timestampAt($stripeSubscription['trial_end'] ?? null);

        if ($asOf !== null) {
            $attributes['last_stripe_event_at'] = $asOf;
        }

        $subscription = $this->subscriptionRepository->update($subscription, $attributes);
        $this->entitlements->forget((int) $subscription->salon_id);

        $this->recordTransitions($subscription, $before, $stripeEventId);

        return $subscription;
    }

    /**
     * Checkout 完了時にサロンと Stripe Customer を紐づけ、契約内容を取り込む。
     *
     * @param  array<string, mixed>  $session
     */
    public function applyCheckoutSession(
        array $session,
        ?string $stripeEventId = null,
        ?Carbon $eventOccurredAt = null,
    ): ?Subscription {
        // $eventOccurredAt は冪等性・監査のために受け取るが、下の同期は Stripe から
        // 取り直した最新の内容なので、鮮度は「いま」で記録する。
        $salonId = $this->salonIdFromMetadata($session)
            ?? $this->intOrNull($session['client_reference_id'] ?? null);
        $stripeCustomerId = $this->stringOrNull($session['customer'] ?? null);
        $stripeSubscriptionId = $this->stringOrNull($session['subscription'] ?? null);

        if ($salonId === null || $stripeSubscriptionId === null) {
            return null;
        }

        $subscription = $this->subscriptionRepository->findBySalonId($salonId)
            ?? $this->openContractFor($salonId);

        if ($subscription === null) {
            return null;
        }

        if ($stripeCustomerId !== null) {
            $subscription = $this->subscriptionRepository->update($subscription, [
                'stripe_customer_id' => $stripeCustomerId,
            ]);
        }

        // Checkout セッションは契約内容を持たないため、正本を Stripe から取り直す。
        return $this->syncFromStripe(
            $this->stripeClient->retrieveSubscription($stripeSubscriptionId),
            $stripeEventId,
            Carbon::now()->utc(),
        );
    }

    /**
     * 支払い失敗を記録する。状態そのものは customer.subscription.updated で同期されるため、
     * ここでは監査ログだけを残す（即時停止はしない）。
     *
     * @param  array<string, mixed>  $invoice
     */
    public function recordPaymentFailure(array $invoice, ?string $stripeEventId = null): ?Subscription
    {
        $stripeSubscriptionId = $this->stringOrNull($invoice['subscription'] ?? null)
            ?? $this->stringOrNull($invoice['parent']['subscription_details']['subscription'] ?? null);
        $stripeCustomerId = $this->stringOrNull($invoice['customer'] ?? null);

        $subscription = $stripeSubscriptionId !== null
            ? $this->subscriptionRepository->findByStripeSubscriptionId($stripeSubscriptionId)
            : null;

        $subscription ??= $stripeCustomerId !== null
            ? $this->subscriptionRepository->findByStripeCustomerId($stripeCustomerId)
            : null;

        if ($subscription === null) {
            return null;
        }

        $this->subscriptionRepository->recordEvent([
            'salon_id' => $subscription->salon_id,
            'subscription_id' => $subscription->id,
            'type' => 'payment_failed',
            'from_plan' => $subscription->plan->value,
            'to_plan' => $subscription->plan->value,
            'from_status' => $subscription->status->value,
            'to_status' => $subscription->status->value,
            'stripe_event_id' => $stripeEventId,
            'occurred_at' => Carbon::now()->utc(),
        ]);

        return $subscription;
    }

    /**
     * @param  array<string, mixed>  $stripeSubscription
     */
    private function resolveSubscription(
        array $stripeSubscription,
        string $stripeSubscriptionId,
        ?string $stripeCustomerId,
    ): ?Subscription {
        $subscription = $this->subscriptionRepository->findByStripeSubscriptionId($stripeSubscriptionId);

        if ($subscription !== null) {
            return $subscription;
        }

        $salonId = $this->salonIdFromMetadata($stripeSubscription);

        $existing = $salonId !== null
            ? $this->subscriptionRepository->findBySalonId($salonId)
            : ($stripeCustomerId !== null
                ? $this->subscriptionRepository->findByStripeCustomerId($stripeCustomerId)
                : null);

        if ($existing !== null) {
            return $this->mayTakeOver($existing, $stripeSubscriptionId, $stripeSubscription)
                ? $existing
                : null;
        }

        return $salonId !== null ? $this->openContractFor($salonId) : null;
    }

    /**
     * いま持っている契約行を、このイベントの内容で上書きしてよいか。
     *
     * 3D Secure を途中でやめるなどして Checkout が完了しないと、その subscription は
     * incomplete のまま残り、約24時間後に incomplete_expired になる。どちらのイベントも
     * metadata.salon_id を持つため、サロン一致だけで引き当てると
     * **いま使えている契約を無効な状態で上書きして全機能を止めてしまう**
     * （last_stripe_event_at の比較では、これらは「より新しい」ため防げない）。
     *
     * 判定は「守るべき契約が今あるか」で行う。stripe_subscription_id の有無では判定しない ——
     * バックフィル移行と salon:create-owner が作る行は stripe 未連携のまま status=active であり、
     * 本番の既存サロンはすべてこの形をしているため、そこを素通しにすると全社的に破壊される。
     *
     * @param  array<string, mixed>  $stripeSubscription
     */
    private function mayTakeOver(Subscription $existing, string $stripeSubscriptionId, array $stripeSubscription): bool
    {
        // 同じ契約自身の更新は常に反映する（解約・失効もこの経路）
        if ($existing->stripe_subscription_id === $stripeSubscriptionId) {
            return true;
        }

        // 別契約からの引き継ぎは、その契約がいま有効なときだけ許す（解約後の再契約・乗り換え）
        if (SubscriptionStatus::tryFrom((string) ($stripeSubscription['status'] ?? ''))?->grantsAccess() === true) {
            return true;
        }

        // 守るべき契約が無ければ、無効な状態のイベントでも紐づけてよい（初回 Checkout の incomplete など）
        return ! $existing->status->grantsAccess();
    }

    /**
     * 契約行が無いサロンのために空の契約を起こす。
     *
     * Checkout を終えた＝すでに課金されているため、ここで取りこぼすと
     * 「支払ったのに全機能 403」から自力で復旧できなくなる。
     * 直後に syncFromStripe が Stripe の内容で上書きするため、ここでは最小の初期値だけを置く。
     *
     * metadata は Stripe 経由で戻ってくる値なので、実在するサロンかを必ず確認する
     * （存在しない ID をそのまま insert すると外部キー違反で 500 になり、Stripe が再送し続ける）。
     */
    private function openContractFor(int $salonId): ?Subscription
    {
        if (! Salon::whereKey($salonId)->exists()) {
            return null;
        }

        return $this->subscriptionRepository->firstOrCreateForSalon($salonId, [
            'plan' => SubscriptionPlan::Lite,
            'status' => SubscriptionStatus::Incomplete,
        ]);
    }

    /**
     * 契約の変化を監査ログに残す。
     *
     * 解約申請は Stripe 上 status が active のまま cancel_at_period_end だけが変わるため、
     * プラン・状態の遷移とは別に記録する（これが無いと「いつ解約されたか」を追えない）。
     *
     * @param  array{plan: SubscriptionPlan, status: SubscriptionStatus, cancel_at_period_end: bool, stripe_subscription_id: ?string}  $before
     */
    private function recordTransitions(Subscription $subscription, array $before, ?string $stripeEventId): void
    {
        $planChanged = $before['plan'] !== $subscription->plan;
        $linked = $before['stripe_subscription_id'] === null && $subscription->stripe_subscription_id !== null;

        if ($planChanged || $before['status'] !== $subscription->status || $linked) {
            $this->recordEvent(
                $subscription,
                $this->transitionType($before, $subscription, $planChanged, $linked),
                $before,
                $stripeEventId,
            );
        }

        if ($before['cancel_at_period_end'] !== $subscription->cancel_at_period_end) {
            $this->recordEvent(
                $subscription,
                $subscription->cancel_at_period_end ? 'cancel_requested' : 'cancel_revoked',
                $before,
                $stripeEventId,
            );
        }
    }

    /**
     * @param  array{plan: SubscriptionPlan, status: SubscriptionStatus, cancel_at_period_end: bool, stripe_subscription_id: ?string}  $before
     */
    private function recordEvent(Subscription $subscription, string $type, array $before, ?string $stripeEventId): void
    {
        $this->subscriptionRepository->recordEvent([
            'salon_id' => $subscription->salon_id,
            'subscription_id' => $subscription->id,
            'type' => $type,
            'from_plan' => $before['plan']->value,
            'to_plan' => $subscription->plan->value,
            'from_status' => $before['status']->value,
            'to_status' => $subscription->status->value,
            'stripe_event_id' => $stripeEventId,
            'occurred_at' => Carbon::now()->utc(),
        ]);
    }

    /**
     * @param  array{plan: SubscriptionPlan, status: SubscriptionStatus, cancel_at_period_end: bool, stripe_subscription_id: ?string}  $before
     */
    private function transitionType(array $before, Subscription $after, bool $planChanged, bool $linked): string
    {
        return match (true) {
            $after->status === SubscriptionStatus::Unpaid => 'suspended',
            $after->status === SubscriptionStatus::Canceled => 'ended',
            $linked, ! $before['status']->grantsAccess() && $after->status->grantsAccess() => 'started',
            $planChanged => 'plan_changed',
            default => 'status_changed',
        };
    }

    /**
     * 契約と支払いを動かせるのはオーナーとマネージャーだけ。
     * 一般スタッフの操作でサロンの請求が変わってしまわないようにする
     * （Google 連携モードの変更と同じ扱い）。
     */
    private function assertCanManageBilling(User $user): void
    {
        if (! in_array($user->role, [Role::Owner, Role::Manager], true)) {
            throw new AuthorizationException('ご契約とお支払いを操作する権限がありません。');
        }
    }

    private function requireStripeSubscription(int $salonId): Subscription
    {
        $subscription = $this->subscriptionRepository->findBySalonId($salonId);

        if ($subscription?->stripe_subscription_id === null) {
            throw ValidationException::withMessages([
                'subscription' => ['ご契約がありません。プランを選択して開始してください。'],
            ]);
        }

        return $subscription;
    }

    private function requireStripeCustomer(int $salonId): Subscription
    {
        $subscription = $this->subscriptionRepository->findBySalonId($salonId);

        if ($subscription?->stripe_customer_id === null) {
            throw ValidationException::withMessages([
                'subscription' => ['お支払い情報がまだ登録されていません。'],
            ]);
        }

        return $subscription;
    }

    private function priceIdOrFail(SubscriptionPlan $plan): string
    {
        return $plan->stripePriceId()
            ?? throw new StripeConfigException("{$plan->label()} プランの Stripe Price ID が設定されていません。");
    }

    private function returnUrl(string $result): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $path = (string) config('billing.return_path');

        return $base.$path.'?checkout='.$result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function salonIdFromMetadata(array $payload): ?int
    {
        return $this->intOrNull($payload['metadata']['salon_id'] ?? null);
    }

    /**
     * current_period_* は API バージョンによって subscription 直下と item 側のどちらにも現れる。
     *
     * @param  array<string, mixed>  $subscription
     * @param  array<string, mixed>  $item
     */
    private function timestamp(array $subscription, array $item, string $key): ?Carbon
    {
        return $this->timestampAt($subscription[$key] ?? $item[$key] ?? null);
    }

    private function timestampAt(mixed $value): ?Carbon
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? Carbon::createFromTimestampUTC((int) $value)
            : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
