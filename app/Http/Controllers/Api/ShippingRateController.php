<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingRateRequest;
use App\Services\ShippingQuoteService;
use App\Support\ApiResponse;
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

            return ApiResponse::success(
                data: $result,
                message: empty($result['options'])
                    ? 'Tidak ada pilihan layanan pengiriman yang tersedia.'
                    : 'Pilihan ongkos kirim berhasil dihitung.',
            );
        } catch (RuntimeException $exception) {
            Log::error('Shipping quote creation failed.', [
                'message' => $exception->getMessage(),
            ]);

            return ApiResponse::error(
                message: 'Layanan pengiriman sedang tidak tersedia. Silakan coba kembali.',
                status: 502,
            );
        }
    }
}
