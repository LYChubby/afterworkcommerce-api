<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\MidtransService;
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

        if (!$user instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        try {
            $payment = $midtransService->createPayment(
                $user,
                $order->id
            );

            return response()->json([
                'message' => 'Snap token berhasil dibuat.',

                'data' => [
                    'payment_uuid' => $payment->uuid,
                    'provider_order_id' => $payment->provider_order_id,
                    'gross_amount' => $payment->gross_amount,
                    'status' => $payment->status,
                    'snap_token' => $payment->snap_token,
                    'redirect_url' => $payment->redirect_url,
                ],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
