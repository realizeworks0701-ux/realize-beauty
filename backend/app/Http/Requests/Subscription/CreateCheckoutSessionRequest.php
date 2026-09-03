<?php

namespace App\Http\Requests\Subscription;

use App\Enums\SubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCheckoutSessionRequest extends FormRequest
{
    /**
     * 受け取るのはアプリの plan key のみ。Stripe Price ID をクライアントに指定させない。
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', Rule::enum(SubscriptionPlan::class)],
        ];
    }

    public function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::from($this->validated('plan'));
    }
}
