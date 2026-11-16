<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingRateRequest;
use App\Services\ShippingQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShippingRateController extends Controller
{
    public function __invoke(
        ShippingRateRequest $request,
        ShippingQuoteService $shippingQuoteService
    ): JsonResponse {
        try {
            $result = $shippingQuoteService->createQuotes(
                $request->user(),
                $request->validated('postal_code'),
                $request->validated('items')
            );

            return response()->json([
                'success' => true,
                'message' => empty($result['options'])
                    ? 'Tidak ada pilihan layanan pengiriman yang tersedia.'
                    : 'Pilihan ongkos kirim berhasil dihitung.',
                'data' => $result,
            ]);
        } catch (RuntimeException $exception) {
            Log::error('Shipping quote creation failed.', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Layanan pengiriman sedang tidak tersedia. Silakan coba kembali.',
            ], 502);
        }
    }
}
