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

        return DB::transaction(function () use (
            $payload,
            $event,
            $providerOrderId
        ) {
            $shipment = Shipment::query()
                ->where('provider', 'biteship')
                ->where('provider_order_id', $providerOrderId)
                ->lockForUpdate()
                ->first();

            if (! $shipment) {
                throw new InvalidArgumentException(
                    'Shipment tidak ditemukan untuk order Biteship tersebut.'
                );
            }

            $eventHash = hash(
                'sha256',
                json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );

            // Abaikan webhook yang sudah pernah diproses.
            if (ShipmentEvent::where('event_hash', $eventHash)->exists()) {
                return false;
            }

            $status = $payload['status'] ?? null;

            ShipmentEvent::create([
                'uuid' => (string) Str::uuid(),
                'shipment_id' => $shipment->id,
                'event_type' => $event,
                'status' => is_string($status) ? $status : null,
                'description' => $this->getDescription($event, $payload),
                'location' => $payload['location'] ?? null,
                'occurred_at' => now(),
                'event_hash' => $eventHash,
                'payload' => $payload,
            ]);

            // Simpan informasi terbaru dari Biteship.
            if (! empty($payload['status'])) {
                $shipment->status = $payload['status'];
            }

            if (! empty($payload['courier_tracking_id'])) {
                $shipment->tracking_id = $payload['courier_tracking_id'];
            }

            if (! empty($payload['courier_waybill_id'])) {
                $shipment->waybill_id = $payload['courier_waybill_id'];
            }

            if (! empty($payload['courier_link'])) {
                $shipment->tracking_url = $payload['courier_link'];
            }

            if ($event === 'order.price') {
                $price = $payload['price'] ?? $payload['order_price'] ?? null;

                if (is_numeric($price) && $price >= 0) {
                    $shipment->actual_shipping_fee = (int) $price;
                }
            }

            if ($event === 'order.status' && $status === 'delivered') {
                $shipment->delivered_at ??= now();
            }

            $shipment->save();

            if ($event === 'order.status' && is_string($status)) {
                $this->syncOrderStatus($shipment, $status);
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

    private function syncOrderStatus(
        Shipment $shipment,
        string $shipmentStatus
    ): void {
        $normalizedStatus = strtolower($shipmentStatus);

        $targetStatus = match ($normalizedStatus) {
            'confirmed',
            'allocated' => Order::STATUS_PROCESSING,

            'pickingup',
            'picked',
            'intransit',
            'droppingoff' => Order::STATUS_SHIPPED,

            'delivered' => Order::STATUS_DELIVERED,

            'returnintransit',
            'returned' => Order::STATUS_RETURNED,

            default => null,
        };

        // Status yang tidak dikenali tidak mengubah order.
        if ($targetStatus === null) {
            return;
        }

        $order = Order::query()
            ->whereKey($shipment->order_id)
            ->lockForUpdate()
            ->first();

        if (! $order) {
            return;
        }

        // Jangan mengubah order yang sudah dibatalkan atau kedaluwarsa.
        if (in_array($order->status, [
            Order::STATUS_CANCELLED,
            Order::STATUS_EXPIRED,
        ], true)) {
            return;
        }

        // Status returned tidak boleh diturunkan kembali.
        if ($order->status === Order::STATUS_RETURNED) {
            return;
        }

        // Status returned memiliki prioritas khusus.
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

        // Abaikan webhook lama yang menyebabkan status mundur.
        if ($targetPriority <= $currentPriority) {
            return;
        }

        $order->status = $targetStatus;
        $order->save();
    }
}
