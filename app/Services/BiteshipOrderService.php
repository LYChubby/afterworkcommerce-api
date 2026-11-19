<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\Warehouse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BiteshipOrderService
{
    public function createForOrder(int $orderId): Shipment
    {
        // Lock order first to keep lock ordering consistent across services.
        $shipment = DB::transaction(function () use ($orderId) {
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($orderId);

            $existingShipment = Shipment::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            // An existing provider order must never be created again.
            if ($existingShipment?->provider_order_id) {
                return $existingShipment;
            }

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

            $courierSnapshot = $order->shipping_courier_snapshot ?? [];

            $courierCode = $courierSnapshot['courier_code'] ?? null;
            $serviceCode = $courierSnapshot['courier_service_code'] ?? null;

            if (!is_string($courierCode) || trim($courierCode) === '') {
                throw new RuntimeException(
                    'Informasi kurir tidak lengkap.'
                );
            }

            if ($existingShipment) {
                return $existingShipment;
            }

            return Shipment::create([
                'order_id' => $order->id,
                'provider' => 'biteship',
                'courier_code' => $courierCode,
                'courier_service_code' => $serviceCode,
                'courier_service_name' => $courierSnapshot['courier_service_name'] ?? null,
                'courier_name' => $courierSnapshot['courier_name'] ?? null,
                'status' => 'creating',
            ]);
        });

        if ($shipment->provider_order_id) {
            return $shipment;
        }

        try {
            // Reload the order after the transaction to use current persisted data.
            $order = Order::with([
                'items.productVariant.product',
            ])->findOrFail($orderId);

            $warehouse = Warehouse::findOrFail($order->warehouse_id);

            $payload = $this->buildPayload($order, $warehouse);

            $response = $this->sendCreateRequest($payload);

            // Recover an order that Biteship created in an earlier request.
            if (
                !$response->successful()
                && (int) $response->json('code') === 40002060
            ) {
                $providerOrderId = $response->json('details.order_id');

                if (
                    !is_string($providerOrderId)
                    || trim($providerOrderId) === ''
                ) {
                    throw new RuntimeException(
                        'Biteship mendeteksi reference_id duplikat, tetapi ID order tidak tersedia.'
                    );
                }

                $response = $this->fetchProviderOrder($providerOrderId);
            }

            $this->validateProviderResponse($response);

            return $this->saveProviderResponse($shipment, $response);
        } catch (Throwable $exception) {
            // Never overwrite a shipment that already has a provider order ID.
            $shipment->refresh();

            if (!$shipment->provider_order_id) {
                $shipment->update([
                    'status' => 'creation_failed',
                ]);
            }

            throw $exception;
        }
    }

    private function sendCreateRequest(array $payload): Response
    {
        return Http::withHeaders($this->headers())
            ->timeout(config('biteship.timeout', 20))
            ->post(
                $this->baseUrl() . '/orders',
                $payload
            );
    }

    private function fetchProviderOrder(string $providerOrderId): Response
    {
        return Http::withHeaders($this->headers())
            ->timeout(config('biteship.timeout', 20))
            ->get(
                $this->baseUrl() . '/orders/' . rawurlencode($providerOrderId)
            );
    }

    private function headers(): array
    {
        $apiKey = config('biteship.api_key');

        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException(
                'Konfigurasi API key Biteship belum tersedia.'
            );
        }

        return [
            'Authorization' => $apiKey,
            'Accept' => 'application/json',
        ];
    }

    private function baseUrl(): string
    {
        $baseUrl = config('biteship.base_url');

        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            throw new RuntimeException(
                'Konfigurasi base URL Biteship belum tersedia.'
            );
        }

        return rtrim($baseUrl, '/');
    }

    private function buildPayload(
        Order $order,
        Warehouse $warehouse
    ): array {
        $destination = $order->shipping_address_snapshot ?? [];
        $courier = $order->shipping_courier_snapshot ?? [];

        $courierCompany = $courier['courier_code'] ?? null;
        $courierType = $courier['courier_service_code'] ?? null;

        if (
            !is_string($courierCompany)
            || trim($courierCompany) === ''
            || !is_string($courierType)
            || trim($courierType) === ''
        ) {
            throw new RuntimeException(
                'Informasi kurir atau layanan pengiriman tidak lengkap.'
            );
        }

        $this->validateAddress($destination, 'tujuan');

        $this->validateWarehouse($warehouse);

        if ($order->items->isEmpty()) {
            throw new RuntimeException(
                'Order tidak memiliki item untuk dikirim.'
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

            if (
                !is_numeric($product->weight_grams)
                || (float) $product->weight_grams <= 0
            ) {
                throw new RuntimeException(
                    "Berat produk {$product->name} belum diatur."
                );
            }

            if (
                !is_numeric($orderItem->quantity)
                || (int) $orderItem->quantity <= 0
                || (float) $orderItem->quantity !== (float) (int) $orderItem->quantity
            ) {
                throw new RuntimeException(
                    'Kuantitas item order tidak valid.'
                );
            }

            $unitValue = (float) $orderItem->unit_price
                - (float) $orderItem->unit_discount;

            if (!is_finite($unitValue) || $unitValue < 0) {
                throw new RuntimeException(
                    'Nilai produk setelah diskon tidak valid.'
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
                'value' => (int) round($unitValue),
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
                    if (!is_numeric($value) || (float) $value <= 0) {
                        throw new RuntimeException(
                            "Dimensi {$key} produk {$product->name} tidak valid."
                        );
                    }

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

    private function validateAddress(array $address, string $type): void
    {
        foreach (
            [
                'recipient_name',
                'phone',
                'address_line',
                'city',
                'province',
                'postal_code',
            ] as $field
        ) {
            $value = $address[$field] ?? null;

            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException(
                    "Data alamat {$type} tidak lengkap: {$field}."
                );
            }
        }

        if (!preg_match('/^\d{5}$/', $address['postal_code'])) {
            throw new RuntimeException(
                "Kode pos alamat {$type} tidak valid."
            );
        }
    }

    private function validateWarehouse(Warehouse $warehouse): void
    {
        foreach (
            [
                'recipient_name',
                'phone',
                'address_line',
                'city',
                'province',
                'postal_code',
            ] as $field
        ) {
            $value = $warehouse->{$field};

            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException(
                    "Data warehouse tidak lengkap: {$field}."
                );
            }
        }

        if (!preg_match('/^\d{5}$/', $warehouse->postal_code)) {
            throw new RuntimeException(
                'Kode pos warehouse tidak valid.'
            );
        }
    }

    private function validateProviderResponse(Response $response): void
    {
        if (!$response->successful()) {
            throw new RuntimeException(
                'Biteship gagal memproses shipment. HTTP status: '
                    . $response->status()
            );
        }

        $data = $response->json();

        if (
            !is_array($data)
            || ($data['success'] ?? null) !== true
            || !isset($data['id'])
            || !is_string($data['id'])
            || trim($data['id']) === ''
        ) {
            throw new RuntimeException(
                'Respons Biteship tidak valid atau tidak memiliki ID order.'
            );
        }

        // Validasi status jika provider mengirimkan field tersebut.
        if (array_key_exists('status', $data)) {
            $status = $data['status'];

            if (
                !is_string($status)
                || trim($status) === ''
                || !$this->isKnownShipmentStatus($status)
            ) {
                throw new RuntimeException(
                    'Status pengiriman pada respons Biteship tidak valid.'
                );
            }
        }

        // Validasi struktur data kurir.
        if (
            isset($data['courier'])
            && !is_array($data['courier'])
        ) {
            throw new RuntimeException(
                'Data kurir pada respons Biteship tidak valid.'
            );
        }

        $courier = $data['courier'] ?? [];

        // Tracking URL boleh tidak tersedia, tetapi jika dikirim harus valid.
        if (
            array_key_exists('link', $courier)
            && $courier['link'] !== null
        ) {
            $trackingUrl = $courier['link'];

            if (
                !is_string($trackingUrl)
                || filter_var($trackingUrl, FILTER_VALIDATE_URL) === false
                || !in_array(
                    strtolower((string) parse_url($trackingUrl, PHP_URL_SCHEME)),
                    ['http', 'https'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Tracking URL pada respons Biteship tidak valid.'
                );
            }
        }

        // Validasi field kurir yang dikirim oleh provider.
        foreach (
            [
                'company',
                'type',
                'tracking_id',
                'waybill_id',
            ] as $field
        ) {
            if (
                array_key_exists($field, $courier)
                && $courier[$field] !== null
                && (
                    !is_string($courier[$field])
                    || trim($courier[$field]) === ''
                )
            ) {
                throw new RuntimeException(
                    "Field courier.{$field} pada respons Biteship tidak valid."
                );
            }
        }

        // Validasi biaya pengiriman.
        if (array_key_exists('price', $data) && $data['price'] !== null) {
            $price = $data['price'];

            $isValidPrice = (
                is_int($price)
                && $price >= 0
            ) || (
                is_string($price)
                && preg_match('/^\d+$/', $price) === 1
                && (
                    strlen(ltrim($price, '0')) < strlen((string) PHP_INT_MAX)
                    || (
                        strlen(ltrim($price, '0')) === strlen((string) PHP_INT_MAX)
                        && strcmp(
                            ltrim($price, '0'),
                            (string) PHP_INT_MAX
                        ) <= 0
                    )
                )
            );

            if (!$isValidPrice) {
                throw new RuntimeException(
                    'Biaya pengiriman pada respons Biteship tidak valid.'
                );
            }
        }
    }

    private function saveProviderResponse(
        Shipment $shipment,
        Response $response
    ): Shipment {
        $data = $response->json();
        $courier = $data['courier'] ?? [];

        $shipment->update([
            'provider_order_id' => $data['id'],
            'tracking_id' => $courier['tracking_id'] ?? null,
            'waybill_id' => $courier['waybill_id'] ?? null,
            'courier_code' => $courier['company']
                ?? $shipment->courier_code,
            'courier_service_code' => $courier['type']
                ?? $shipment->courier_service_code,
            'status' => strtolower(trim($data['status'] ?? 'confirmed')),
            'tracking_url' => $courier['link'] ?? null,
            'actual_shipping_fee' => isset($data['price'])
                ? (int) $data['price']
                : null,
            'provider_response' => $data,
        ]);

        return $shipment->fresh();
    }

    private function isKnownShipmentStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), [
            'confirmed',
            'scheduled',
            'allocated',
            'picking_up',
            'pickingup',
            'picked',
            'in_transit',
            'intransit',
            'dropping_off',
            'droppingoff',
            'delivered',
            'return_in_transit',
            'returnintransit',
            'returned',
        ], true);
    }
}
