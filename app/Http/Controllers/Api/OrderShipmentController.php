<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentEventResource;
use App\Http\Resources\ShipmentResource;
use App\Models\Order;
use App\Models\ShipmentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderShipmentController extends Controller
{
    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless(
            (int) $order->user_id === (int) $request->user()->id,
            404
        );

        $shipment = $order->shipment;

        if (!$shipment) {
            return response()->json([
                'message' => 'Informasi pengiriman belum tersedia.',
                'data' => null,
            ]);
        }

        $events = ShipmentEvent::query()
            ->where('shipment_id', $shipment->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'message' => 'Informasi pelacakan berhasil diambil.',
            'data' => [
                'order' => [
                    'uuid' => $order->uuid,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                ],
                'shipment' => new ShipmentResource($shipment),
                'events' => ShipmentEventResource::collection($events),
            ],
        ]);
    }
}
