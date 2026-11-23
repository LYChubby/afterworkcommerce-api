<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\MidtransService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function store(
        Request $request,
        Order $order,
        MidtransService $midtransService
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiResponse::error(
                message: 'Unauthenticated.',
                status: 401,
            );
        }

        try {
            $payment = $midtransService->createPayment(
                $user,
                $order->id
            );

            return ApiResponse::success(
                data: [
                    'payment_uuid' => $payment->uuid,
                    'provider_order_id' => $payment->provider_order_id,
                    'gross_amount' => $payment->gross_amount,
                    'status' => $payment->status,
                    'snap_token' => $payment->snap_token,
                    'redirect_url' => $payment->redirect_url,
                ],
                message: 'Snap token berhasil dibuat.',
            );
        } catch (RuntimeException $exception) {
            return ApiResponse::error(
                message: $exception->getMessage(),
                status: 422,
            );
        }
    }
}
