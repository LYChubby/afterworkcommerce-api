<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ShippingQuote;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderService
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    /**
     * Membuat pesanan pending beserta reservasi stok.
     *
     * Harga produk selalu dibaca dari database.
     */
    public function createPendingOrder(
        User $user,
        array $items,
        array $shippingAddressSnapshot,
        string $shippingQuoteUuid,
        ?int $shippingAddressId = null,
        ?string $customerNote = null
    ): Order {
        if (empty($items)) {
            throw ValidationException::withMessages([
                'items' => 'Keranjang tidak boleh kosong.',
            ]);
        }

        $normalizedCartItems = $this->normalizeCartForShippingQuote($items);

        return DB::transaction(function () use (
            $user,
            $items,
            $shippingAddressSnapshot,
            $shippingQuoteUuid,
            $normalizedCartItems,
            $shippingAddressId,
            $customerNote
        ) {
            /*
             * 1. Tentukan alamat pengiriman.
             *
             * Jika menggunakan alamat tersimpan, pastikan alamat
             * tersebut memang milik pelanggan.
             */
            if ($shippingAddressId !== null) {
                $address = $user->addresses()
                    ->findOrFail($shippingAddressId);

                $addressSnapshot = [
                    'recipient_name' => $address->recipient_name,
                    'phone' => $address->phone,
                    'address_line' => $address->address_line,
                    'province' => $address->province,
                    'city' => $address->city,
                    'district' => $address->district,
                    'postal_code' => $address->postal_code,
                    'country' => $address->country,
                    'notes' => $address->notes,
                ];
            } else {
                $addressSnapshot = $shippingAddressSnapshot;
            }

            $requiredAddressFields = [
                'recipient_name',
                'phone',
                'address_line',
                'province',
                'city',
                'postal_code',
                'country',
            ];

            foreach ($requiredAddressFields as $field) {
                if (
                    ! isset($addressSnapshot[$field]) ||
                    trim((string) $addressSnapshot[$field]) === ''
                ) {
                    throw new RuntimeException(
                        "Informasi alamat {$field} wajib diisi."
                    );
                }
            }

            /*
             * 2. Ambil gudang aktif.
             *
             * Untuk MVP ATELIER saat ini hanya menggunakan
             * satu gudang aktif.
             */
            $warehouse = Warehouse::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->firstOrFail();

            $shippingQuote = ShippingQuote::query()
                ->where('uuid', $shippingQuoteUuid)
                ->lockForUpdate()
                ->first();

            if (! $shippingQuote) {
                throw ValidationException::withMessages([
                    'shipping_quote_uuid' => 'Pilihan pengiriman tidak ditemukan.',
                ]);
            }

            if ((int) $shippingQuote->user_id !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'shipping_quote_uuid' => 'Pilihan pengiriman bukan milik akun Anda.',
                ]);
            }

            if (! $shippingQuote->isUsable()) {
                throw ValidationException::withMessages([
                    'shipping_quote_uuid' => 'Pilihan pengiriman sudah kedaluwarsa atau digunakan.',
                ]);
            }

            if ((int) $shippingQuote->warehouse_id !== (int) $warehouse->id) {
                throw ValidationException::withMessages([
                    'shipping_quote_uuid' => 'Gudang pengiriman tidak sesuai.',
                ]);
            }

            $quotePostalCode = trim(
                (string) ($shippingQuote->destination_snapshot['postal_code'] ?? '')
            );

            $orderPostalCode = trim(
                (string) ($shippingAddressSnapshot['postal_code'] ?? '')
            );

            if (
                $quotePostalCode === '' ||
                $orderPostalCode === '' ||
                $quotePostalCode !== $orderPostalCode
            ) {
                throw ValidationException::withMessages([
                    'shipping_quote_uuid' => 'Kode pos alamat tidak sesuai dengan pilihan pengiriman.',
                ]);
            }

            $quoteItems = collect($shippingQuote->items_snapshot)
                ->map(fn ($item) => [
                    'variant_id' => (int) ($item['variant_id'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                ])
                ->sortBy('variant_id')
                ->values()
                ->all();

            if ($quoteItems !== $normalizedCartItems) {
                throw ValidationException::withMessages([
                    'shipping_quote_uuid' => 'Isi keranjang berubah. Silakan hitung ulang ongkos kirim.',
                ]);
            }

            /*
             * 3. Normalisasi item.
             *
             * Jika frontend mengirim varian yang sama lebih dari
             * satu kali, jumlahnya digabung.
             */
            $normalizedItems = [];

            foreach ($items as $item) {
                $variantId = (int) ($item['product_variant_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);

                if ($variantId < 1 || $quantity < 1) {
                    throw new RuntimeException(
                        'Data item pesanan tidak valid.'
                    );
                }

                $normalizedItems[$variantId] =
                    ($normalizedItems[$variantId] ?? 0) + $quantity;
            }

            /*
             * 4. Ambil data varian dan harga dari database.
             *
             * Jangan pernah menerima harga dari frontend.
             */
            $orderLines = [];

            $subtotal = 0;
            $itemDiscountTotal = 0;

            foreach ($normalizedItems as $variantId => $quantity) {
                $variant = ProductVariant::query()
                    ->with(['product', 'size', 'color'])
                    ->findOrFail($variantId);

                if (! $variant->is_active) {
                    throw new RuntimeException(
                        'Salah satu varian produk tidak aktif.'
                    );
                }

                $product = $variant->product;

                if (! $product || ! $product->is_active) {
                    throw new RuntimeException(
                        'Salah satu produk tidak tersedia.'
                    );
                }

                if (
                    ! $variant->size ||
                    ! $variant->color ||
                    ! $variant->size->is_active ||
                    ! $variant->color->is_active
                ) {
                    throw new RuntimeException(
                        'Informasi ukuran atau warna produk tidak tersedia.'
                    );
                }

                $unitPrice = (int) round((float) $product->price);

                $effectivePrice = (int) round(
                    (float) $product->effective_price
                );

                $unitDiscount = $unitPrice - $effectivePrice;

                if (
                    $unitPrice <= 0 ||
                    $unitDiscount < 0 ||
                    $effectivePrice <= 0
                ) {
                    throw new RuntimeException(
                        'Harga produk tidak valid.'
                    );
                }

                $lineSubtotal = $unitPrice * $quantity;
                $lineDiscount = $unitDiscount * $quantity;

                $subtotal += $lineSubtotal;
                $itemDiscountTotal += $lineDiscount;

                $orderLines[] = [
                    'variant_id' => $variant->id,
                    'quantity' => $quantity,
                    'product_name' => $product->name,
                    'product_slug' => $product->slug,
                    'sku' => $variant->sku,
                    'size_name' => $variant->size->name,
                    'color_name' => $variant->color->name,
                    'color_hex_code' => $variant->color->hex_code,
                    'unit_price' => $unitPrice,
                    'unit_discount' => $unitDiscount,
                    'line_total' => $effectivePrice * $quantity,
                ];
            }

            /*
             * 5. Hitung total dan batas pembayaran.
             */
            $grandTotal = $subtotal - $itemDiscountTotal + $shippingQuote->shipping_fee;

            $paymentDeadline = now()->addMinutes(30);

            /*
             * 6. Buat pesanan.
             */
            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'warehouse_id' => $warehouse->id,
                'shipping_address_id' => $shippingAddressId,
                'shipping_address_snapshot' => $addressSnapshot,
                'status' => 'pending',
                'payment_status' => 'pending',
                'subtotal' => $subtotal,
                'item_discount_total' => $itemDiscountTotal,
                'shipping_cost' => $shippingQuote->shipping_fee,

                'shipping_courier_snapshot' => [
                    'quote_uuid' => $shippingQuote->uuid,
                    'courier_code' => $shippingQuote->courier_code,
                    'courier_name' => $shippingQuote->courier_name,
                    'courier_service_code' => $shippingQuote->courier_service_code,
                    'courier_service_name' => $shippingQuote->courier_service_name,
                    'description' => $shippingQuote->description,
                    'duration' => $shippingQuote->duration,
                    'shipping_fee' => $shippingQuote->shipping_fee,
                    'currency' => $shippingQuote->currency,
                ],
                'grand_total' => $grandTotal,
                'currency' => 'IDR',
                'payment_deadline_at' => $paymentDeadline,
                'customer_note' => $customerNote,
            ]);

            $shippingQuote->update([
                'order_id' => $order->id,
                'used_at' => now(),
            ]);

            /*
             * 7. Simpan snapshot setiap item.
             */
            foreach ($orderLines as $line) {
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $line['variant_id'],
                    'product_name' => $line['product_name'],
                    'product_slug' => $line['product_slug'],
                    'sku' => $line['sku'],
                    'size_name' => $line['size_name'],
                    'color_name' => $line['color_name'],
                    'color_hex_code' => $line['color_hex_code'],
                    'unit_price' => $line['unit_price'],
                    'unit_discount' => $line['unit_discount'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                ]);

                /*
                 * 8. Reservasi stok.
                 *
                 * Jika salah satu reservasi gagal, seluruh
                 * transaksi pembuatan pesanan akan di-rollback.
                 */
                $this->inventoryService->reserveForOrderItem(
                    $orderItem->id
                );
            }

            return $order->load([
                'items',
                'inventoryReservations',
            ]);
        }, 3);
    }

    /**
     * Membuat nomor pesanan unik.
     */
    protected function generateOrderNumber(): string
    {
        do {
            $orderNumber = 'ATL-'.
                now()->format('Ymd').
                '-'.
                Str::upper(Str::random(8));
        } while (
            Order::query()
                ->where('order_number', $orderNumber)
                ->exists()
        );

        return $orderNumber;
    }

    private function normalizeCartForShippingQuote(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $variantId = (int) (
                $item['variant_id']
                ?? $item['product_variant_id']
                ?? 0
            );

            $quantity = (int) ($item['quantity'] ?? 0);

            if ($variantId <= 0 || $quantity <= 0) {
                throw ValidationException::withMessages([
                    'items' => 'Variasi produk dan jumlah harus valid.',
                ]);
            }

            $normalized[$variantId] = ($normalized[$variantId] ?? 0) + $quantity;
        }

        ksort($normalized);

        return collect($normalized)
            ->map(fn ($quantity, $variantId) => [
                'variant_id' => (int) $variantId,
                'quantity' => (int) $quantity,
            ])
            ->values()
            ->all();
    }
}
