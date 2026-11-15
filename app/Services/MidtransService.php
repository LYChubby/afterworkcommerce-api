<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Midtrans\Config;
use Midtrans\Snap;
use RuntimeException;

class MidtransService
{
    public function createPayment(User $user, int $orderId): Payment
    {
        return DB::transaction(function () use ($user, $orderId) {

            // Kunci order agar dua request pembayaran bersamaan
            // tidak membuat transaksi baru secara bersamaan.
            $order = Order::query()
                ->where('user_id', $user->id)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->with(['items', 'payments'])
                ->firstOrFail();

            if (
                $order->status !== 'pending' ||
                $order->payment_status !== 'pending'
            ) {
                throw new RuntimeException(
                    'Pesanan tidak dapat dibayar.'
                );
            }

            if (
                !$order->payment_deadline_at ||
                $order->payment_deadline_at->isPast()
            ) {
                throw new RuntimeException(
                    'Batas waktu pembayaran telah berakhir.'
                );
            }

            // Gunakan Snap token yang sudah ada jika masih tersedia.
            $existingPayment = $order->payments()
                ->where('provider', 'midtrans')
                ->where('status', 'pending')
                ->whereNotNull('snap_token')
                ->latest('id')
                ->first();

            if ($existingPayment) {
                return $existingPayment;
            }

            $this->configureMidtrans();

            $providerOrderId = $order->order_number
                . '-P-'
                . Str::upper(Str::random(6));

            $grossAmount = (int) round(
                (float) $order->grand_total
            );

            if ($grossAmount <= 0) {
                throw new RuntimeException(
                    'Nominal pembayaran tidak valid.'
                );
            }

            $itemDetails = $this->buildItemDetails($order);

            $itemDetailsTotal = collect($itemDetails)->sum(
                fn(array $item) =>
                $item['price'] * $item['quantity']
            );

            if ($itemDetailsTotal !== $grossAmount) {
                throw new RuntimeException(
                    'Total item pembayaran tidak sesuai dengan total pesanan.'
                );
            }

            $user = $order->user()->firstOrFail();

            $shippingAddress = $order->shipping_address_snapshot;

            $customerDetails = [
                'first_name' => $shippingAddress['recipient_name'],
                'email' => $user->email,
                'phone' => $shippingAddress['phone'],
                'shipping_address' => [
                    'first_name' => $shippingAddress['recipient_name'],
                    'phone' => $shippingAddress['phone'],
                    'address' => $shippingAddress['address_line'],
                    'city' => $shippingAddress['city'],
                    'postal_code' => $shippingAddress['postal_code'],
                    'country_code' => 'IDN',
                ],
            ];

            $params = [
                'transaction_details' => [
                    'order_id' => $providerOrderId,
                    'gross_amount' => $grossAmount,
                ],

                'item_details' => $itemDetails,

                'customer_details' => $customerDetails,
            ];

            // Request ke Midtrans dilakukan setelah order dikunci.
            // Ini menjaga agar proses expiration tidak mengubah
            // order ketika transaksi sedang dibuat.
            $snapResponse = Snap::createTransaction($params);

            $payment = $order->payments()->create([
                'provider' => 'midtrans',
                'provider_order_id' => $providerOrderId,
                'snap_token' => $snapResponse->token,
                'redirect_url' => $snapResponse->redirect_url,
                'gross_amount' => $grossAmount,
                'status' => 'pending',
            ]);

            return $payment;
        }, 3);
    }

    private function configureMidtrans(): void
    {
        $serverKey = config('midtrans.server_key');

        if (!$serverKey) {
            throw new RuntimeException(
                'Midtrans Server Key belum dikonfigurasi.'
            );
        }

        Config::$serverKey = $serverKey;

        Config::$isProduction = config(
            'midtrans.is_production',
            false
        );

        Config::$isSanitized = config(
            'midtrans.is_sanitized',
            true
        );

        Config::$is3ds = config(
            'midtrans.is_3ds',
            true
        );
    }

    private function buildItemDetails(Order $order): array
    {
        $items = [];

        foreach ($order->items as $orderItem) {
            $unitPrice = (int) round(
                (float) $orderItem->unit_price -
                    (float) $orderItem->unit_discount
            );

            $items[] = [
                'id' => $orderItem->sku,
                'price' => $unitPrice,
                'quantity' => $orderItem->quantity,
                'name' => Str::limit(
                    $orderItem->product_name
                        . ' - '
                        . $orderItem->size_name
                        . ' - '
                        . $orderItem->color_name,
                    50,
                    ''
                ),
            ];
        }

        if ((int) round((float) $order->shipping_cost) > 0) {
            $items[] = [
                'id' => 'SHIPPING',
                'price' => (int) round(
                    (float) $order->shipping_cost
                ),
                'quantity' => 1,
                'name' => 'Shipping Cost',
            ];
        }

        return $items;
    }
}
