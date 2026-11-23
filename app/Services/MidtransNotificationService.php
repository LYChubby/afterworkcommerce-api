<?php

namespace App\Services;

use App\Jobs\CreateBiteshipShipmentJob;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class MidtransNotificationService
{
    public function __construct(
        private InventoryService $inventoryService,
        private OrderCancellationService $cancellationService,
    ) {}

    public function handle(array $payload): void
    {
        $this->validatePayload($payload);
        $this->verifySignature($payload);

        // Cari payment untuk mendapatkan order_id.
        // Penguncian sebenarnya dilakukan dengan urutan order -> payment.
        $paymentReference = Payment::query()
            ->where('provider', 'midtrans')
            ->where('provider_order_id', $payload['order_id'])
            ->firstOrFail();

        DB::transaction(function () use ($payload, $paymentReference): void {

            // Urutan lock harus konsisten dengan service inventory
            // dan order cancellation.
            $order = Order::query()
                ->whereKey($paymentReference->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment = Payment::query()
                ->whereKey($paymentReference->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $payment->order_id !== $order->id ||
                $payment->provider_order_id !== $payload['order_id']
            ) {
                throw new RuntimeException(
                    'Payment tidak cocok dengan order.'
                );
            }

            $this->validateGrossAmount($payment, $payload);

            // Jangan memproses ulang pembayaran yang sudah final.
            if (in_array($payment->status, [
                'paid',
                'paid_late',
                'paid_duplicate',
            ], true)) {
                return;
            }

            $transactionStatus = strtolower(
                $payload['transaction_status']
            );

            $payment->transaction_id =
                $payload['transaction_id'];

            $payment->transaction_status =
                $transactionStatus;

            $payment->payment_type =
                $payload['payment_type'] ?? null;

            $payment->fraud_status =
                $payload['fraud_status'] ?? null;

            $payment->notification_payload = $payload;

            // Tentukan apakah transaksi benar-benar berhasil.
            if ($this->isSuccessfulPayment($payload)) {
                $this->processSuccessfulPayment(
                    $order,
                    $payment
                );

                return;
            }

            // Status non-success tidak boleh mengubah order
            // menjadi paid.
            $payment->status = $this->mapPaymentStatus(
                $transactionStatus,
                $payload['fraud_status'] ?? null
            );

            $payment->save();
        }, 3);
    }

    private function validatePayload(array $payload): void
    {
        $requiredFields = [
            'order_id',
            'status_code',
            'gross_amount',
            'transaction_status',
            'transaction_id',
            'signature_key',
        ];

        foreach ($requiredFields as $field) {
            if (
                ! isset($payload[$field]) ||
                ! is_scalar($payload[$field]) ||
                trim((string) $payload[$field]) === ''
            ) {
                throw new InvalidArgumentException(
                    "Field notifikasi {$field} tidak valid."
                );
            }
        }

        if (! is_numeric($payload['gross_amount'])) {
            throw new InvalidArgumentException(
                'Nominal pembayaran tidak valid.'
            );
        }

        if (
            isset($payload['currency']) &&
            strtoupper($payload['currency']) !== 'IDR'
        ) {
            throw new InvalidArgumentException(
                'Mata uang pembayaran tidak sesuai.'
            );
        }
    }

    private function verifySignature(array $payload): void
    {
        $serverKey = config('midtrans.server_key');

        if (! $serverKey) {
            throw new RuntimeException(
                'Midtrans Server Key belum dikonfigurasi.'
            );
        }

        $signatureInput =
            $payload['order_id'].
            $payload['status_code'].
            $payload['gross_amount'].
            $serverKey;

        $expectedSignature = hash(
            'sha512',
            $signatureInput
        );

        if (! hash_equals(
            $expectedSignature,
            (string) $payload['signature_key']
        )) {
            throw new InvalidArgumentException(
                'Signature notifikasi Midtrans tidak valid.'
            );
        }
    }

    private function validateGrossAmount(
        Payment $payment,
        array $payload
    ): void {
        $expectedAmount = (int) round(
            (float) $payment->gross_amount * 100
        );

        $receivedAmount = (int) round(
            (float) $payload['gross_amount'] * 100
        );

        if ($expectedAmount !== $receivedAmount) {
            throw new RuntimeException(
                'Nominal notifikasi tidak sesuai dengan payment.'
            );
        }
    }

    private function isSuccessfulPayment(array $payload): bool
    {
        $transactionStatus = strtolower(
            $payload['transaction_status']
        );

        $isSuccessStatus = in_array(
            $transactionStatus,
            ['settlement', 'capture'],
            true
        );

        $isSuccessfulResponse =
            (string) $payload['status_code'] === '200';

        $fraudStatus = strtolower(
            (string) ($payload['fraud_status'] ?? '')
        );

        $fraudAccepted =
            ! array_key_exists('fraud_status', $payload) ||
            $fraudStatus === 'accept';

        return $isSuccessStatus
            && $isSuccessfulResponse
            && $fraudAccepted;
    }

    private function processSuccessfulPayment(
        Order $order,
        Payment $payment
    ): void {
        // Pembayaran kedua yang berhasil untuk order yang sudah paid
        // perlu ditandai untuk pemeriksaan manual.
        if ($order->payment_status === 'paid') {
            $payment->status = 'paid_duplicate';
            $payment->save();

            return;
        }

        // Order dibatalkan, kedaluwarsa, atau deadline terlewati.
        if (
            $order->status !== 'pending' ||
            $order->payment_status !== 'pending' ||
            ! $order->payment_deadline_at ||
            $order->payment_deadline_at->isPast()
        ) {
            $payment->status = 'paid_late';
            $payment->save();

            // Jika scheduler belum sempat mengakhiri order,
            // lepaskan reservasi sekarang.
            if (
                $order->status === 'pending' &&
                $order->payment_status === 'pending' &&
                $order->payment_deadline_at &&
                $order->payment_deadline_at->isPast()
            ) {
                $this->cancellationService->expirePendingOrder(
                    $order->id
                );
            }

            return;
        }

        // Tandai payment dan order sudah dibayar.
        $payment->status = 'paid';
        $payment->paid_at = now();
        $payment->save();

        $order->payment_status = 'paid';
        $order->paid_at = now();
        $order->save();

        // Konfirmasi seluruh reservasi stok.
        $activeReservations = $order->inventoryReservations()
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();

        foreach ($activeReservations as $reservation) {
            $this->inventoryService->confirmReservation(
                $reservation->id
            );
        }

        // Setelah seluruh reservasi berhasil dikonfirmasi,
        // order masuk ke tahap pemrosesan.
        $order->status = 'processing';
        $order->save();

        // Buat shipment setelah transaksi pembayaran berhasil commit.
        CreateBiteshipShipmentJob::dispatch($order->id)
            ->afterCommit();
    }

    private function mapPaymentStatus(
        string $transactionStatus,
        ?string $fraudStatus
    ): string {
        if (
            $transactionStatus === 'capture' &&
            strtolower((string) $fraudStatus) === 'challenge'
        ) {
            return 'challenge';
        }

        return match ($transactionStatus) {
            'pending' => 'pending',
            'deny' => 'failed',
            'cancel' => 'cancelled',
            'expire' => 'expired',
            'refund', 'partial_refund' => 'refunded',
            default => 'pending',
        };
    }
}
