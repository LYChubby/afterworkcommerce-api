<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(
        protected CheckoutService $checkoutService
    ) {}

    /**
     * Membuat pesanan dari pembelian langsung atau Cart.
     */
    public function store(CheckoutRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $data = $request->validated();

        $order = $this->checkoutService->checkout(
            user: $user,
            source: $data['source'],
            items: $data['items'],
            addressUuid: $data['address_uuid'],
            shippingQuoteUuid: $data['shipping_quote_uuid'],
            customerNote: $data['customer_note'] ?? null,
        );

        return ApiResponse::success(
            data: new OrderResource($order),
            message: 'Order created successfully.',
            status: 201,
        );
    }
}
