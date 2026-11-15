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
        return DB::transaction(function () use ($orderItemId) {

            // Kunci item pesanan
            $item = OrderItem::query()
                ->lockForUpdate()
                ->findOrFail($orderItemId);

            // Kunci pesanan
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($item->order_id);

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

            // Cegah reservasi ganda untuk item yang sama
            $existingReservation = InventoryReservation::query()
                ->where('order_item_id', $item->id)
                ->lockForUpdate()
                ->first();

            if ($existingReservation) {
                throw new RuntimeException(
                    'Item pesanan sudah memiliki reservasi stok.'
                );
            }

            if (!$item->product_variant_id) {
                throw new RuntimeException(
                    'Varian produk tidak tersedia.'
                );
            }

            // Kunci varian agar tidak diubah transaksi lain
            $variant = ProductVariant::query()
                ->lockForUpdate()
                ->findOrFail($item->product_variant_id);

            if (!$variant->is_active) {
                throw new RuntimeException(
                    'Varian produk tidak aktif.'
                );
            }

            $availableStock = (
                $variant->stock_on_hand -
                $variant->stock_reserved
            );

            if ($availableStock < $item->quantity) {
                throw new RuntimeException(
                    'Stok produk tidak mencukupi.'
                );
            }

            // Snapshot stok sebelum perubahan
            $stockOnHandBefore = $variant->stock_on_hand;
            $stockReservedBefore = $variant->stock_reserved;

            // Tambahkan stok yang dicadangkan
            $variant->stock_reserved += $item->quantity;
            $variant->save();

            // Buat reservasi
            $reservation = InventoryReservation::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'product_variant_id' => $variant->id,
                'quantity' => $item->quantity,
                'status' => 'active',
                'expires_at' => $order->payment_deadline_at,
            ]);

            // Catat riwayat perubahan stok
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
        });
    }

    /**
     * Mengonfirmasi reservasi setelah pembayaran berhasil diverifikasi.
     */
    public function confirmReservation(
        int $reservationId
    ): InventoryReservation {
        return DB::transaction(function () use ($reservationId) {

            $reservation = InventoryReservation::query()
                ->lockForUpdate()
                ->findOrFail($reservationId);

            // Idempotent: tidak mengurangi stok dua kali
            if ($reservation->status === 'confirmed') {
                return $reservation;
            }

            if ($reservation->status !== 'active') {
                throw new RuntimeException(
                    'Reservasi tidak dapat dikonfirmasi.'
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

            // Stok fisik berkurang dan reservasi dilepaskan
            $variant->stock_on_hand -= $reservation->quantity;
            $variant->stock_reserved -= $reservation->quantity;
            $variant->save();

            $reservation->status = 'confirmed';
            $reservation->confirmed_at = now();
            $reservation->save();

            $order = Order::findOrFail($reservation->order_id);

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
        });
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

        return DB::transaction(function () use (
            $reservationId,
            $finalStatus
        ) {

            $reservation = InventoryReservation::query()
                ->lockForUpdate()
                ->findOrFail($reservationId);

            // Operasi berulang tidak mengubah stok lagi
            if (
                in_array(
                    $reservation->status,
                    ['released', 'expired'],
                    true
                )
            ) {
                return $reservation;
            }

            if ($reservation->status !== 'active') {
                throw new RuntimeException(
                    'Reservasi tidak dapat dilepaskan.'
                );
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

            // Kembalikan stok ke jumlah tersedia
            $variant->stock_reserved -= $reservation->quantity;
            $variant->save();

            $reservation->status = $finalStatus;
            $reservation->released_at = now();
            $reservation->save();

            $order = Order::findOrFail($reservation->order_id);

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
        });
    }
}
