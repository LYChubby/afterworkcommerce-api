<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ShipmentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderShipmentController extends Controller
{
    public function show(Request $request, Order $order): JsonResponse
    {
        // Pastikan order merupakan milik pelanggan yang sedang login.
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
            ->get([
                'id',
                'event_type',
                'status',
                'description',
                'location',
                'occurred_at',
            ]);

        return response()->json([
            'message' => 'Informasi pelacakan berhasil diambil.',
            'data' => [
                'order' => [
                    'uuid' => $order->uuid,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                ],
                'shipment' => [
                    'provider' => $shipment->provider,
                    'courier_name' => $shipment->courier_name,
                    'courier_service_name' => $shipment->courier_service_name,
                    'tracking_id' => $shipment->tracking_id,
                    'waybill_id' => $shipment->waybill_id,
                    'tracking_url' => $shipment->tracking_url,
                    'status' => $shipment->status,
                    'shipped_at' => $shipment->shipped_at,
                    'delivered_at' => $shipment->delivered_at,
                ],
                'events' => $events,
            ],
        ]);
    }
}
