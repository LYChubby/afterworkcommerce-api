<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        int $shippingCost = 0,
        ?int $shippingAddressId = null,
        ?string $customerNote = null
    ): Order {
        if (empty($items)) {
            throw new RuntimeException(
                'Pesanan harus memiliki minimal satu item.'
            );
        }

        if ($shippingCost < 0) {
            throw new RuntimeException(
                'Biaya pengiriman tidak valid.'
            );
        }

        return DB::transaction(function () use (
            $user,
            $items,
            $shippingAddressSnapshot,
            $shippingCost,
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
                    !isset($addressSnapshot[$field]) ||
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

                if (!$variant->is_active) {
                    throw new RuntimeException(
                        'Salah satu varian produk tidak aktif.'
                    );
                }

                $product = $variant->product;

                if (!$product || !$product->is_active) {
                    throw new RuntimeException(
                        'Salah satu produk tidak tersedia.'
                    );
                }

                if (
                    !$variant->size ||
                    !$variant->color ||
                    !$variant->size->is_active ||
                    !$variant->color->is_active
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
            $grandTotal = $subtotal
                - $itemDiscountTotal
                + $shippingCost;

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
                'shipping_cost' => $shippingCost,
                'grand_total' => $grandTotal,
                'currency' => 'IDR',
                'payment_deadline_at' => $paymentDeadline,
                'customer_note' => $customerNote,
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
            $orderNumber = 'ATL-' .
                now()->format('Ymd') .
                '-' .
                Str::upper(Str::random(8));
        } while (
            Order::query()
            ->where('order_number', $orderNumber)
            ->exists()
        );

        return $orderNumber;
    }
}
