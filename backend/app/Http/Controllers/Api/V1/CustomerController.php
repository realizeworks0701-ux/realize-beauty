<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $customers = $this->customerService->list(
            $request->user()->salon_id,
            $request->only(['keyword', 'gender', 'visited_after', 'visited_before', 'per_page', 'sort']),
        );

        return response()->json(CustomerResource::collection($customers)->response()->getData(true));
    }

    public function store(CreateCustomerRequest $request): JsonResponse
    {
        $customer = $this->customerService->create(
            $request->user()->salon_id,
            $request->validated(),
        );

        return response()->json(['data' => new CustomerResource($customer)], 201);
    }

    public function show(Request $request, int $customerId): JsonResponse
    {
        $customer = $this->customerService->find(
            $request->user()->salon_id,
            $customerId,
        );

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function update(UpdateCustomerRequest $request, int $customerId): JsonResponse
    {
        $customer = $this->customerService->update(
            $request->user()->salon_id,
            $customerId,
            $request->validated(),
        );

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function destroy(Request $request, int $customerId): Response
    {
        $this->customerService->delete(
            $request->user()->salon_id,
            $customerId,
        );

        return response()->noContent();
    }
}
