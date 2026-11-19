<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BiteshipWebhookService
{
    public function handle(array $payload): bool
    {
        $event = $payload['event'] ?? null;
        $providerOrderId = $payload['order_id'] ?? null;

        if (! is_string($event) || ! in_array($event, [
            'order.status',
            'order.waybill_id',
            'order.price',
        ], true)) {
            throw new InvalidArgumentException('Event Biteship tidak valid.');
        }

        if (! is_string($providerOrderId) || $providerOrderId === '') {
            throw new InvalidArgumentException('Order ID Biteship tidak tersedia.');
        }

        $this->validatePayload($event, $payload);

        return DB::transaction(function () use (
            $payload,
            $event,
            $providerOrderId
        ) {

            $shipmentReference = Shipment::query()
                ->where('provider', 'biteship')
                ->where('provider_order_id', $providerOrderId)
                ->first();

            if (! $shipmentReference) {
                throw new InvalidArgumentException(
                    'Shipment tidak ditemukan untuk order Biteship tersebut.'
                );
            }

            // Urutan lock harus konsisten: order terlebih dahulu.
            $order = Order::query()
                ->whereKey($shipmentReference->order_id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new InvalidArgumentException(
                    'Order untuk shipment Biteship tidak ditemukan.'
                );
            }

            // Setelah order terkunci, baru kunci shipment.
            $shipment = Shipment::query()
                ->whereKey($shipmentReference->id)
                ->where('provider', 'biteship')
                ->where('provider_order_id', $providerOrderId)
                ->lockForUpdate()
                ->first();

            if (! $shipment) {
                throw new InvalidArgumentException(
                    'Shipment tidak ditemukan untuk order Biteship tersebut.'
                );
            }

            $normalizedPayload = $this->normalizePayloadForHash($payload);

            $eventHash = hash(
                'sha256',
                json_encode(
                    $normalizedPayload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );

            // Abaikan webhook yang sudah pernah diproses.
            if (ShipmentEvent::where('event_hash', $eventHash)->exists()) {
                return false;
            }

            $rawStatus = isset($payload['status'])
                ? trim($payload['status'])
                : null;

            $normalizedStatus = $rawStatus !== null
                ? strtolower(str_replace(' ', '', $rawStatus))
                : null;

            $shipmentStatus = match ($normalizedStatus) {
                'intransit' => 'inTransit',
                'returnintransit' => 'returnInTransit',
                default => $normalizedStatus,
            };

            $location = isset($payload['location'])
                && trim($payload['location']) !== ''
                ? trim($payload['location'])
                : null;

            // Simpan event webhook.
            ShipmentEvent::create([
                'uuid' => (string) Str::uuid(),
                'shipment_id' => $shipment->id,
                'event_type' => $event,
                'status' => $rawStatus,
                'description' => $this->getDescription($event, $payload),
                'location' => $location,
                'occurred_at' => now(),
                'event_hash' => $eventHash,
                'payload' => $payload,
            ]);

            // Perbarui tracking ID jika nilainya tidak kosong.
            if (
                isset($payload['courier_tracking_id'])
                && trim($payload['courier_tracking_id']) !== ''
            ) {
                $shipment->tracking_id = trim($payload['courier_tracking_id']);
            }

            // Perbarui nomor resi jika nilainya tidak kosong.
            if (
                isset($payload['courier_waybill_id'])
                && trim($payload['courier_waybill_id']) !== ''
            ) {
                $shipment->waybill_id = trim($payload['courier_waybill_id']);
            }

            // Perbarui URL tracking jika nilainya tidak kosong.
            if (
                isset($payload['courier_link'])
                && trim($payload['courier_link']) !== ''
            ) {
                $shipment->tracking_url = trim($payload['courier_link']);
            }

            // Simpan status shipment hanya untuk event status.
            if (
                $event === 'order.status'
                && $normalizedStatus !== null
                && $this->isKnownShipmentStatus($normalizedStatus)
            ) {
                $shipment->status = $shipmentStatus;

                if ($normalizedStatus === 'delivered') {
                    $shipment->delivered_at ??= now();
                }
            }


            // Perbarui biaya pengiriman.
            if ($event === 'order.price') {
                $price = $payload['price'] ?? $payload['order_price'];

                $shipment->actual_shipping_fee = (int) $price;
            }

            $shipment->save();

            if (
                $event === 'order.status'
                && $normalizedStatus !== null
                && $this->isKnownShipmentStatus($normalizedStatus)
            ) {
                $this->syncOrderStatus($order, $normalizedStatus);
            }

            return true;
        });
    }

    private function getDescription(string $event, array $payload): ?string
    {
        return match ($event) {
            'order.status' => 'Status pengiriman diperbarui oleh Biteship.',
            'order.waybill_id' => 'Nomor resi pengiriman diperbarui oleh Biteship.',
            'order.price' => 'Biaya pengiriman diperbarui oleh Biteship.',
            default => null,
        };
    }

    private function getShipmentStatusPriority(string $status): int
    {
        return match (strtolower(trim($status))) {
            'confirmed', 'scheduled', 'allocated' => 1,

            'picking_up', 'pickingup' => 2,

            'picked' => 3,

            'in_transit', 'intransit' => 4,

            'dropping_off', 'droppingoff' => 5,

            'delivered' => 6,

            'return_in_transit', 'returnintransit' => 7,

            'returned' => 8,

            default => 0,
        };
    }


    private function syncOrderStatus(
        Order $order,
        string $shipmentStatus
    ): void {
        $normalizedStatus = strtolower(trim($shipmentStatus));

        $targetStatus = match ($normalizedStatus) {
            'confirmed', 'scheduled', 'allocated' =>
            Order::STATUS_PROCESSING,

            'picking_up',
            'pickingup',
            'picked',
            'in_transit',
            'intransit',
            'dropping_off',
            'droppingoff' =>
            Order::STATUS_SHIPPED,

            'delivered' =>
            Order::STATUS_DELIVERED,

            'return_in_transit',
            'returnintransit',
            'returned' =>
            Order::STATUS_RETURNED,

            default => null,
        };

        if ($targetStatus === null) {
            return;
        }

        if (in_array($order->status, [
            Order::STATUS_CANCELLED,
            Order::STATUS_EXPIRED,
            Order::STATUS_RETURNED,
        ], true)) {
            return;
        }

        // Status returned memiliki prioritas terminal.
        if ($targetStatus === Order::STATUS_RETURNED) {
            $order->status = Order::STATUS_RETURNED;
            $order->save();

            return;
        }

        $priority = [
            Order::STATUS_PENDING => 0,
            Order::STATUS_PROCESSING => 1,
            Order::STATUS_SHIPPED => 2,
            Order::STATUS_DELIVERED => 3,
        ];

        $currentPriority = $priority[$order->status] ?? -1;
        $targetPriority = $priority[$targetStatus] ?? -1;

        // Abaikan webhook lama yang menyebabkan status order mundur.
        if ($targetPriority <= $currentPriority) {
            return;
        }

        $order->status = $targetStatus;
        $order->save();
    }


    private function normalizePayloadForHash(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalizePayloadForHash($value);
            }
        }

        return $payload;
    }


    private function validatePayload(string $event, array $payload): void
    {
        // Field yang harus berupa string jika dikirim.
        $stringFields = [
            'status',
            'location',
            'courier_tracking_id',
            'courier_waybill_id',
            'courier_link',
        ];

        foreach ($stringFields as $field) {
            if (
                array_key_exists($field, $payload)
                && $payload[$field] !== null
                && ! is_string($payload[$field])
            ) {
                throw new InvalidArgumentException(
                    "Field {$field} harus berupa string."
                );
            }
        }

        // Status wajib berupa string untuk event order.status.
        if (
            $event === 'order.status'
            && (
                ! isset($payload['status'])
                || trim($payload['status']) === ''
            )
        ) {
            throw new InvalidArgumentException(
                'Status pengiriman tidak tersedia.'
            );
        }

        // Validasi URL tracking jika dikirim.
        if (
            isset($payload['courier_link'])
            && (
                filter_var($payload['courier_link'], FILTER_VALIDATE_URL) === false
                || ! in_array(
                    strtolower((string) parse_url($payload['courier_link'], PHP_URL_SCHEME)),
                    ['http', 'https'],
                    true
                )
            )
        ) {
            throw new InvalidArgumentException(
                'Courier tracking link harus berupa URL HTTP atau HTTPS yang valid.'
            );
        }

        // Validasi harga pengiriman harus berupa integer nonnegatif
        // dan tidak boleh melebihi batas PHP_INT_MAX.
        foreach (['price', 'order_price'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $price = $payload[$field];

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
                            ltrim($price, '0') ?: '0',
                            (string) PHP_INT_MAX
                        ) <= 0
                    )
                )
            );

            if (! $isValidPrice) {
                throw new InvalidArgumentException(
                    "Field {$field} harus berupa integer nonnegatif yang valid."
                );
            }
        }

        // Event order.price harus membawa harga.
        if (
            $event === 'order.price'
            && ! array_key_exists('price', $payload)
            && ! array_key_exists('order_price', $payload)
        ) {
            throw new InvalidArgumentException(
                'Harga pengiriman tidak tersedia.'
            );
        }
    }

    private function isKnownShipmentStatus(string $status): bool
    {
        return in_array(
            strtolower(trim($status)),
            [
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
            ],
            true
        );
    }
}
