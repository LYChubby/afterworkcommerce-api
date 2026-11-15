<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransNotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class MidtransNotificationController extends Controller
{
    public function __invoke(
        Request $request,
        MidtransNotificationService $notificationService
    ): JsonResponse {
        try {
            $notificationService->handle($request->all());

            return response()->json([
                'message' => 'Midtrans notification processed.',
            ], 200);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => 'Invalid Midtrans notification.',
            ], 403);
        } catch (ModelNotFoundException $exception) {
            return response()->json([
                'message' => 'Payment transaction not found.',
            ], 404);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
