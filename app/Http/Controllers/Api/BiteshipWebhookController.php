<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BiteshipWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BiteshipWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        BiteshipWebhookService $webhookService
    ): JsonResponse {
        $headerName = config('biteship.webhook_header');
        $secret = config('biteship.webhook_secret');

        // Pastikan secret sudah dikonfigurasi.
        if (! is_string($secret) || $secret === '') {
            return response()->json([
                'message' => 'Webhook authentication is not configured.',
            ], 503);
        }

        // Validasi header autentikasi dari Biteship.
        $providedSecret = $request->header($headerName);

        if (
            ! is_string($providedSecret) ||
            ! hash_equals($secret, $providedSecret)
        ) {
            return response()->json([
                'message' => 'Unauthorized webhook request.',
            ], 401);
        }

        // Pastikan request menggunakan JSON.
        if (! $request->isJson()) {
            return response()->json([
                'message' => 'Content-Type must be application/json.',
            ], 415);
        }

        $payload = $request->json()->all();

        try {
            $processed = $webhookService->handle($payload);

            return response()->json([
                'message' => $processed
                    ? 'Webhook processed successfully.'
                    : 'Webhook already processed.',
                'processed' => $processed,
            ], 200);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
