<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\ChangePlanRequest;
use App\Http\Requests\Subscription\CreateCheckoutSessionRequest;
use App\Http\Requests\Subscription\SyncCheckoutRequest;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly EntitlementService $entitlements,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $salonId = $request->user()->salon_id;
        $subscription = $this->subscriptionService->findForSalon($salonId);

        return response()->json([
            'data' => [
                'subscription' => $subscription === null ? null : new SubscriptionResource($subscription),
                'plan' => $this->entitlements->planFor($salonId)?->value,
                'features' => $this->entitlements->features($salonId),
                'plans' => PlanResource::collection(SubscriptionPlan::cases()),
            ],
        ]);
    }

    public function checkout(CreateCheckoutSessionRequest $request): JsonResponse
    {
        $url = $this->subscriptionService->startCheckout($request->user(), $request->plan());

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * Checkout から戻った直後に結果を取り込む。Webhook 到着待ちの窓を閉じるためのもので、
     * 契約状態の正本はあくまで Stripe（と Webhook 同期）にある。
     */
    public function syncCheckout(SyncCheckoutRequest $request): JsonResponse
    {
        $subscription = $this->subscriptionService->syncCheckoutSession(
            $request->user(),
            $request->sessionId(),
        );

        return response()->json([
            'data' => $subscription === null ? null : new SubscriptionResource($subscription),
        ]);
    }

    public function portal(Request $request): JsonResponse
    {
        $url = $this->subscriptionService->createPortalSession($request->user());

        return response()->json(['data' => ['url' => $url]]);
    }

    public function changePlan(ChangePlanRequest $request): JsonResponse
    {
        $subscription = $this->subscriptionService->changePlan($request->user(), $request->plan());

        return response()->json(['data' => new SubscriptionResource($subscription)]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $subscription = $this->subscriptionService->cancel($request->user());

        return response()->json(['data' => new SubscriptionResource($subscription)]);
    }

    public function resume(Request $request): JsonResponse
    {
        $subscription = $this->subscriptionService->resume($request->user());

        return response()->json(['data' => new SubscriptionResource($subscription)]);
    }
}
