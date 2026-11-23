<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderCancellationService
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    /**
     * Membatalkan pesanan oleh pelanggan.
     */
    public function cancelPendingOrder(
        int $orderId,
        int $userId
    ): Order {
        return DB::transaction(function () use ($orderId, $userId) {

            // Kunci pesanan terlebih dahulu.
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($orderId);

            // Pastikan pesanan milik pelanggan yang melakukan pembatalan.
            if ($order->user_id !== $userId) {
                throw new RuntimeException(
                    'Anda tidak memiliki akses untuk membatalkan pesanan ini.'
                );
            }

            // Pembatalan berulang tidak boleh mengubah stok lagi.
            if ($order->status === 'cancelled') {
                return $order->load([
                    'items',
                    'inventoryReservations',
                ]);
            }

            if ($order->status !== 'pending') {
                throw new RuntimeException(
                    'Pesanan tidak dapat dibatalkan pada status saat ini.'
                );
            }

            if ($order->payment_status !== 'pending') {
                throw new RuntimeException(
                    'Pesanan yang sudah dibayar tidak dapat dibatalkan.'
                );
            }

            // Ubah status terlebih dahulu karena InventoryService
            // mensyaratkan status cancelled untuk pelepasan.
            $order->status = 'cancelled';
            $order->cancelled_at = now();
            $order->save();

            // Lepaskan semua reservasi yang masih aktif.
            $reservations = $order->inventoryReservations()
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $this->inventoryService->releaseReservation(
                    $reservation->id,
                    'released'
                );
            }

            return $order->fresh([
                'items',
                'inventoryReservations',
            ]);
        }, 3);
    }

    /**
     * Mengakhiri pesanan yang telah melewati batas pembayaran.
     */
    public function expirePendingOrder(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {

            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($orderId);

            // Idempotent: pesanan yang sudah expired tidak diproses ulang.
            if ($order->status === 'expired') {
                return $order->load([
                    'items',
                    'inventoryReservations',
                ]);
            }

            if ($order->status !== 'pending') {
                throw new RuntimeException(
                    'Pesanan tidak berada dalam status pending.'
                );
            }

            if ($order->payment_status !== 'pending') {
                throw new RuntimeException(
                    'Pesanan yang sudah dibayar tidak dapat kedaluwarsa.'
                );
            }

            if (
                ! $order->payment_deadline_at ||
                $order->payment_deadline_at->isFuture()
            ) {
                throw new RuntimeException(
                    'Batas waktu pembayaran belum berakhir.'
                );
            }

            // Lepaskan semua reservasi yang masih aktif.
            // Status order tetap pending selama proses ini karena
            // InventoryService memvalidasi status tersebut.
            $reservations = $order->inventoryReservations()
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $this->inventoryService->releaseReservation(
                    $reservation->id,
                    'expired'
                );
            }

            // Setelah semua reservasi dilepaskan, tandai expired.
            $order->status = 'expired';
            $order->save();

            return $order->fresh([
                'items',
                'inventoryReservations',
            ]);
        }, 3);
    }
}
