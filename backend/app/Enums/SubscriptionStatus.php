<?php

namespace App\Enums;

/**
 * Stripe Subscription の状態をそのまま写した契約状態（ADR-029）。
 *
 * Stripe が返す status 文字列をアプリ側で読み替えず保持し、
 * 「利用できるか」の判断は grantsAccess() に一本化する。
 * 画面やサービスは status を直接比較せず、必ず grantsAccess() 経由で判定する。
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Unpaid = 'unpaid';
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Paused = 'paused';

    /**
     * 契約中の機能を利用できる状態か。
     *
     * past_due（初回の支払い失敗〜Stripe の自動再試行中）は利用を止めない。
     * Stripe Billing の回収フローが尽きると unpaid へ遷移し、そこで初めて利用停止となる。
     * cancel_at_period_end による解約申請中は Stripe 上 active のままのため、
     * 期間終了までは自然にここで true を返す。
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue => true,
            self::Canceled, self::Unpaid, self::Incomplete, self::IncompleteExpired, self::Paused => false,
        };
    }

    /**
     * 利用可能な状態の値の一覧。SQL で契約中のサロンを絞り込むために使う。
     *
     * @return list<string>
     */
    public static function grantingAccessValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->grantsAccess()),
        ));
    }

    /**
     * 支払いに問題があり、ユーザーへの注意喚起が必要な状態か。
     */
    public function needsPaymentAttention(): bool
    {
        return match ($this) {
            self::PastDue, self::Unpaid, self::Incomplete => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'トライアル中',
            self::Active => '利用中',
            self::PastDue => 'お支払い確認中',
            self::Canceled => '解約済み',
            self::Unpaid => '利用停止中',
            self::Incomplete => 'お支払い手続き未完了',
            self::IncompleteExpired => 'お支払い手続き期限切れ',
            self::Paused => '一時停止中',
        };
    }
}
