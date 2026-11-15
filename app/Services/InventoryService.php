<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService
{
    /**
     * Mencadangkan stok untuk satu item pesanan.
     */
    public function reserveForOrderItem(
        int $orderItemId
    ): InventoryReservation {
        // order_id bersifat tetap, sehingga boleh dibaca sebelum lock.
        $initialItem = OrderItem::query()->findOrFail($orderItemId);

        return DB::transaction(function () use ($orderItemId, $initialItem) {
            // Urutan lock standar: order -> item/reservation -> variant.
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($initialItem->order_id);

            $item = OrderItem::query()
                ->lockForUpdate()
                ->findOrFail($orderItemId);

            if ($item->order_id !== $order->id) {
                throw new RuntimeException(
                    'Item pesanan tidak sesuai dengan pesanan.'
                );
            }

            // Retry request tidak boleh membuat reservasi kedua.
            $existingReservation = InventoryReservation::query()
                ->where('order_item_id', $item->id)
                ->lockForUpdate()
                ->first();

            if ($existingReservation) {
                return $existingReservation;
            }

            if ($order->status !== 'pending') {
                throw new RuntimeException(
                    'Pesanan tidak berada dalam status pending.'
                );
            }

            if ($order->payment_status !== 'pending') {
                throw new RuntimeException(
                    'Status pembayaran tidak valid untuk reservasi.'
                );
            }

            if (
                !$order->payment_deadline_at ||
                $order->payment_deadline_at->lessThanOrEqualTo(now())
            ) {
                throw new RuntimeException(
                    'Batas waktu pembayaran telah berakhir.'
                );
            }

            if (!$item->product_variant_id) {
                throw new RuntimeException(
                    'Varian produk tidak tersedia.'
                );
            }

            if ($item->quantity < 1) {
                throw new RuntimeException(
                    'Jumlah item pesanan tidak valid.'
                );
            }

            $variant = ProductVariant::query()
                ->lockForUpdate()
                ->findOrFail($item->product_variant_id);

            if (!$variant->is_active) {
                throw new RuntimeException(
                    'Varian produk tidak aktif.'
                );
            }

            $availableStock =
                $variant->stock_on_hand - $variant->stock_reserved;

            if ($availableStock < $item->quantity) {
                throw new RuntimeException(
                    'Stok produk tidak mencukupi.'
                );
            }

            $stockOnHandBefore = $variant->stock_on_hand;
            $stockReservedBefore = $variant->stock_reserved;

            $variant->stock_reserved += $item->quantity;
            $variant->save();

            $reservation = InventoryReservation::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'product_variant_id' => $variant->id,
                'quantity' => $item->quantity,
                'status' => 'active',
                'expires_at' => $order->payment_deadline_at,
            ]);

            InventoryMovement::create([
                'warehouse_id' => $order->warehouse_id,
                'product_variant_id' => $variant->id,
                'movement_type' => 'reservation',
                'quantity' => $item->quantity,
                'stock_on_hand_before' => $stockOnHandBefore,
                'stock_reserved_before' => $stockReservedBefore,
                'stock_on_hand_after' => $variant->stock_on_hand,
                'stock_reserved_after' => $variant->stock_reserved,
                'reference_type' => 'inventory_reservation',
                'reference_id' => $reservation->id,
                'notes' => 'Reservasi stok untuk pesanan ' .
                    $order->order_number,
            ]);

            return $reservation;
        }, 3);
    }

    /**
     * Mengonfirmasi reservasi setelah pembayaran diverifikasi.
     *
     * Order harus sudah memiliki payment_status = paid
     * dan paid_at harus menunjukkan pembayaran sebelum deadline.
     */
    public function confirmReservation(
        int $reservationId
    ): InventoryReservation {
        // Ambil order_id untuk menentukan lock pertama.
        $initialReservation = InventoryReservation::query()
            ->findOrFail($reservationId);

        return DB::transaction(function () use (
            $reservationId,
            $initialReservation
        ) {
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($initialReservation->order_id);

            $reservation = InventoryReservation::query()
                ->lockForUpdate()
                ->findOrFail($reservationId);

            if ($reservation->order_id !== $order->id) {
                throw new RuntimeException(
                    'Reservasi tidak sesuai dengan pesanan.'
                );
            }

            // Idempotent: tidak mengurangi stok dua kali.
            if ($reservation->status === 'confirmed') {
                return $reservation;
            }

            if ($reservation->status !== 'active') {
                throw new RuntimeException(
                    'Reservasi tidak dapat dikonfirmasi.'
                );
            }

            if ($order->status === 'cancelled') {
                throw new RuntimeException(
                    'Pesanan sudah dibatalkan.'
                );
            }

            if ($order->payment_status !== 'paid' || !$order->paid_at) {
                throw new RuntimeException(
                    'Pembayaran pesanan belum diverifikasi.'
                );
            }

            if (
                !$reservation->expires_at ||
                $order->paid_at->greaterThan($reservation->expires_at)
            ) {
                throw new RuntimeException(
                    'Pembayaran terjadi setelah batas waktu reservasi.'
                );
            }

            $variant = ProductVariant::query()
                ->lockForUpdate()
                ->findOrFail($reservation->product_variant_id);

            if (
                $variant->stock_reserved < $reservation->quantity ||
                $variant->stock_on_hand < $reservation->quantity
            ) {
                throw new RuntimeException(
                    'Data stok tidak konsisten.'
                );
            }

            $stockOnHandBefore = $variant->stock_on_hand;
            $stockReservedBefore = $variant->stock_reserved;

            $variant->stock_on_hand -= $reservation->quantity;
            $variant->stock_reserved -= $reservation->quantity;
            $variant->save();

            $reservation->status = 'confirmed';
            $reservation->confirmed_at = now();
            $reservation->save();

            InventoryMovement::create([
                'warehouse_id' => $order->warehouse_id,
                'product_variant_id' => $variant->id,
                'movement_type' => 'stock_out',
                'quantity' => $reservation->quantity,
                'stock_on_hand_before' => $stockOnHandBefore,
                'stock_reserved_before' => $stockReservedBefore,
                'stock_on_hand_after' => $variant->stock_on_hand,
                'stock_reserved_after' => $variant->stock_reserved,
                'reference_type' => 'inventory_reservation',
                'reference_id' => $reservation->id,
                'notes' => 'Konfirmasi reservasi pesanan ' .
                    $order->order_number,
            ]);

            return $reservation;
        }, 3);
    }

    /**
     * Melepaskan reservasi karena pembatalan atau kedaluwarsa.
     */
    public function releaseReservation(
        int $reservationId,
        string $finalStatus = 'released'
    ): InventoryReservation {
        if (!in_array($finalStatus, ['released', 'expired'], true)) {
            throw new RuntimeException(
                'Status akhir reservasi tidak valid.'
            );
        }

        $initialReservation = InventoryReservation::query()
            ->findOrFail($reservationId);

        return DB::transaction(function () use (
            $reservationId,
            $finalStatus,
            $initialReservation
        ) {
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($initialReservation->order_id);

            $reservation = InventoryReservation::query()
                ->lockForUpdate()
                ->findOrFail($reservationId);

            if ($reservation->order_id !== $order->id) {
                throw new RuntimeException(
                    'Reservasi tidak sesuai dengan pesanan.'
                );
            }

            // Operasi berulang tidak mengubah stok lagi.
            if (in_array(
                $reservation->status,
                ['released', 'expired'],
                true
            )) {
                return $reservation;
            }

            if ($reservation->status !== 'active') {
                throw new RuntimeException(
                    'Reservasi tidak dapat dilepaskan.'
                );
            }

            if ($order->payment_status !== 'pending') {
                throw new RuntimeException(
                    'Reservasi pesanan yang sudah dibayar tidak dapat dilepaskan.'
                );
            }

            if ($finalStatus === 'released') {
                if ($order->status !== 'cancelled') {
                    throw new RuntimeException(
                        'Pesanan harus dibatalkan sebelum reservasi dilepaskan.'
                    );
                }
            }

            if ($finalStatus === 'expired') {
                if ($order->status !== 'pending') {
                    throw new RuntimeException(
                        'Hanya pesanan pending yang dapat kedaluwarsa.'
                    );
                }

                if (
                    !$reservation->expires_at ||
                    $reservation->expires_at->isFuture()
                ) {
                    throw new RuntimeException(
                        'Batas waktu reservasi belum berakhir.'
                    );
                }
            }

            $variant = ProductVariant::query()
                ->lockForUpdate()
                ->findOrFail($reservation->product_variant_id);

            if ($variant->stock_reserved < $reservation->quantity) {
                throw new RuntimeException(
                    'Jumlah stok reservasi tidak konsisten.'
                );
            }

            $stockOnHandBefore = $variant->stock_on_hand;
            $stockReservedBefore = $variant->stock_reserved;

            $variant->stock_reserved -= $reservation->quantity;
            $variant->save();

            $reservation->status = $finalStatus;
            $reservation->released_at = now();
            $reservation->save();

            InventoryMovement::create([
                'warehouse_id' => $order->warehouse_id,
                'product_variant_id' => $variant->id,
                'movement_type' => 'reservation_release',
                'quantity' => $reservation->quantity,
                'stock_on_hand_before' => $stockOnHandBefore,
                'stock_reserved_before' => $stockReservedBefore,
                'stock_on_hand_after' => $variant->stock_on_hand,
                'stock_reserved_after' => $variant->stock_reserved,
                'reference_type' => 'inventory_reservation',
                'reference_id' => $reservation->id,
                'notes' => 'Pelepasan reservasi pesanan ' .
                    $order->order_number,
            ]);

            return $reservation;
        }, 3);
    }
}
