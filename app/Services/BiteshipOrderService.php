<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\Warehouse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BiteshipOrderService
{
    public function createForOrder(int $orderId): Shipment
    {
        // Ambil order beserta item dan data produk untuk pengiriman.
        $order = Order::with([
            'items.productVariant.product',
        ])->findOrFail($orderId);

        if ($order->payment_status !== 'paid') {
            throw new RuntimeException(
                'Shipment hanya boleh dibuat setelah pembayaran terverifikasi.'
            );
        }

        if ($order->status !== 'processing') {
            throw new RuntimeException(
                'Order belum berada dalam status processing.'
            );
        }

        // Buat satu record shipment per order.
        $shipment = DB::transaction(function () use ($orderId) {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($orderId);

            $existingShipment = Shipment::query()
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->first();

            if ($existingShipment) {
                return $existingShipment;
            }

            $courierSnapshot = $lockedOrder->shipping_courier_snapshot ?? [];

            return Shipment::create([
                'order_id' => $lockedOrder->id,
                'provider' => 'biteship',
                'courier_code' => $courierSnapshot['courier_code'],
                'courier_service_code' => $courierSnapshot['courier_service_code'] ?? null,
                'courier_service_name' => $courierSnapshot['courier_service_name'] ?? null,
                'courier_name' => $courierSnapshot['courier_name'] ?? null,
                'status' => 'creating',
            ]);
        });

        // Shipment yang sudah memiliki ID Biteship tidak boleh dibuat ulang.
        if ($shipment->provider_order_id) {
            return $shipment;
        }

        $warehouse = Warehouse::findOrFail($order->warehouse_id);

        try {
            $payload = $this->buildPayload($order, $warehouse);
            $response = Http::withHeaders([
                'Authorization' => config('biteship.api_key'),
                'Accept' => 'application/json',
            ])
                ->timeout(config('biteship.timeout', 20))
                ->post(
                    rtrim(config('biteship.base_url'), '/') . '/orders',
                    $payload
                );

            // Jika reference_id sudah digunakan, coba ambil order lama.
            if (
                !$response->successful()
                && (int) $response->json('code') === 40002060
            ) {
                $existingProviderOrderId = $response->json(
                    'details.order_id'
                );

                if ($existingProviderOrderId) {
                    $response = Http::withHeaders([
                        'Authorization' => config('biteship.api_key'),
                        'Accept' => 'application/json',
                    ])
                        ->timeout(config('biteship.timeout', 20))
                        ->get(
                            rtrim(config('biteship.base_url'), '/')
                                . '/orders/'
                                . $existingProviderOrderId
                        );
                }
            }

            if (
                !$response->successful()
                || $response->json('success') !== true
            ) {
                throw new RuntimeException(
                    'Biteship gagal membuat shipment: '
                        . $response->body()
                );
            }

            return $this->saveProviderResponse($shipment, $response);
        } catch (\Throwable $exception) {
            $shipment->update([
                'status' => 'creation_failed',
            ]);

            throw $exception;
        }
    }

    private function buildPayload(
        Order $order,
        Warehouse $warehouse
    ): array {
        $destination = $order->shipping_address_snapshot;
        $courier = $order->shipping_courier_snapshot;

        $courierCompany = $courier['courier_code'] ?? null;
        $courierType = $courier['courier_service_code'] ?? null;

        if (!$courierCompany || !$courierType) {
            throw new RuntimeException(
                'Informasi kurir atau layanan pengiriman tidak lengkap.'
            );
        }

        $items = [];

        foreach ($order->items as $orderItem) {
            $variant = $orderItem->productVariant;

            if (!$variant || !$variant->product) {
                throw new RuntimeException(
                    'Data produk untuk salah satu item order tidak ditemukan.'
                );
            }

            $product = $variant->product;

            if (!$product->weight_grams || $product->weight_grams <= 0) {
                throw new RuntimeException(
                    "Berat produk {$product->name} belum diatur."
                );
            }

            $item = [
                'name' => $orderItem->product_name,
                'description' => trim(
                    ($orderItem->color_name ?? '')
                        . ' '
                        . ($orderItem->size_name ?? '')
                ),
                'category' => 'fashion',
                'sku' => $orderItem->sku,
                'value' => (int) round(
                    (float) $orderItem->unit_price
                        - (float) $orderItem->unit_discount
                ),
                'quantity' => (int) $orderItem->quantity,
                'weight' => (int) $product->weight_grams,
            ];

            foreach (
                [
                    'height' => $product->height_cm,
                    'length' => $product->length_cm,
                    'width' => $product->width_cm,
                ] as $key => $value
            ) {
                if ($value !== null) {
                    $item[$key] = (int) $value;
                }
            }

            $items[] = $item;
        }

        $originAddress = implode(', ', array_filter([
            $warehouse->address_line,
            $warehouse->district,
            $warehouse->city,
            $warehouse->province,
            $warehouse->postal_code,
            $warehouse->country,
        ]));

        $destinationAddress = implode(', ', array_filter([
            $destination['address_line'] ?? null,
            $destination['district'] ?? null,
            $destination['city'] ?? null,
            $destination['province'] ?? null,
            $destination['postal_code'] ?? null,
            $destination['country'] ?? 'Indonesia',
        ]));

        return [
            'origin_contact_name' => $warehouse->recipient_name,
            'origin_contact_phone' => $warehouse->phone,
            'origin_address' => $originAddress,
            'origin_postal_code' => (int) $warehouse->postal_code,

            'destination_contact_name' => $destination['recipient_name'],
            'destination_contact_phone' => $destination['phone'],
            'destination_address' => $destinationAddress,
            'destination_postal_code' => (int) $destination['postal_code'],
            'destination_note' => $destination['notes'] ?? null,

            'courier_company' => $courierCompany,
            'courier_type' => $courierType,
            'delivery_type' => 'now',

            'reference_id' => $order->order_number,
            'order_note' => $order->customer_note,

            'items' => $items,
        ];
    }

    private function saveProviderResponse(
        Shipment $shipment,
        Response $response
    ): Shipment {
        $data = $response->json();

        $shipment->update([
            'provider_order_id' => $data['id'] ?? null,
            'tracking_id' => $data['courier']['tracking_id'] ?? null,
            'waybill_id' => $data['courier']['waybill_id'] ?? null,
            'courier_code' => $data['courier']['company']
                ?? $shipment->courier_code,
            'courier_service_code' => $data['courier']['type']
                ?? $shipment->courier_service_code,
            'status' => $data['status'] ?? 'confirmed',
            'tracking_url' => $data['courier']['link'] ?? null,
            'actual_shipping_fee' => isset($data['price'])
                ? (int) $data['price']
                : null,
            'provider_response' => $data,
        ]);

        return $shipment->fresh();
    }
}
