<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentEventResource;
use App\Http\Resources\ShipmentResource;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderShipmentController extends Controller
{
    public function show(Request $request, Order $order): JsonResponse
    {
        // Pastikan order merupakan milik pelanggan yang login.
        abort_unless(
            (int) $order->user_id === (int) $request->user()->id,
            404
        );

        // Ambil shipment dan seluruh riwayatnya dengan eager loading.
        $order->load([
            'shipment.events' => function (HasMany $query) {
                $query
                    ->select([
                        'id',
                        'uuid',
                        'shipment_id',
                        'event_type',
                        'status',
                        'description',
                        'location',
                        'occurred_at',
                    ])
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id');
            },
        ]);

        $shipment = $order->shipment;

        if (! $shipment) {
            return ApiResponse::success(
                data: null,
                message: 'Informasi pengiriman belum tersedia.',
            );
        }

        return ApiResponse::success(
            data: [
                'order' => [
                    'uuid' => $order->uuid,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                ],
                'shipment' => new ShipmentResource($shipment),
                'events' => ShipmentEventResource::collection(
                    $shipment->events
                ),
            ],
            message: 'Informasi pelacakan berhasil diambil.',
        );
    }
}
