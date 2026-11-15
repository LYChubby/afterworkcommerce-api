<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderCancellationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class ExpirePendingOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $orderId
    ) {}

    public function handle(
        OrderCancellationService $cancellationService
    ): void {
        $order = Order::query()->find($this->orderId);

        // Pesanan tidak ditemukan atau sudah tidak perlu kedaluwarsa.
        if (
            !$order ||
            $order->status !== 'pending' ||
            $order->payment_status !== 'pending' ||
            !$order->payment_deadline_at ||
            $order->payment_deadline_at->isFuture()
        ) {
            return;
        }

        try {
            $cancellationService->expirePendingOrder($this->orderId);
        } catch (RuntimeException $exception) {
            // Periksa kembali apabila status pesanan berubah
            // ketika Job sedang diproses.
            $currentOrder = Order::query()->find($this->orderId);

            if (
                !$currentOrder ||
                $currentOrder->status !== 'pending' ||
                $currentOrder->payment_status !== 'pending' ||
                !$currentOrder->payment_deadline_at ||
                $currentOrder->payment_deadline_at->isFuture()
            ) {
                return;
            }

            // Jika pesanan masih memenuhi syarat tetapi proses gagal,
            // biarkan Laravel melakukan retry.
            throw $exception;
        }
    }
}
