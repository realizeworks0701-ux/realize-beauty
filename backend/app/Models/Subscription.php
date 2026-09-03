<?php

namespace App\Models;

use App\Enums\Feature;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'salon_id',
        'plan',
        'status',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'canceled_at',
        'ended_at',
        'trial_ends_at',
        'last_stripe_event_at',
    ];

    protected $casts = [
        'plan' => SubscriptionPlan::class,
        'status' => SubscriptionStatus::class,
        'cancel_at_period_end' => 'boolean',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'canceled_at' => 'datetime',
        'ended_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'last_stripe_event_at' => 'datetime',
    ];

    /**
     * この契約で機能を利用できるか。
     *
     * 判定は「契約状態が有効か」と「プランに含まれるか」の2段でしか行わない。
     * 呼び出し側がプラン名や status を直接比較しないための唯一の入口。
     */
    public function can(Feature|string $feature): bool
    {
        $feature = $feature instanceof Feature ? $feature : Feature::tryFrom($feature);

        if ($feature === null || ! $this->status->grantsAccess()) {
            return false;
        }

        return $this->plan->includes($feature);
    }

    /**
     * その機能を実際に利用できる契約だけに絞る。
     *
     * 定期実行のように全サロンを横断するクエリで使う。判定根拠は can() と同じ
     * config/billing.php であり、プラン名を SQL に直接書かない。
     */
    public function scopeGranting(Builder $query, Feature $feature): void
    {
        $query
            ->whereIn('plan', SubscriptionPlan::valuesWithFeature($feature))
            ->whereIn('status', SubscriptionStatus::grantingAccessValues());
    }

    /**
     * 解約申請済みで、まだ利用期間が残っている状態か。
     */
    public function isCancelScheduled(): bool
    {
        return $this->cancel_at_period_end && $this->status->grantsAccess();
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class);
    }
}
