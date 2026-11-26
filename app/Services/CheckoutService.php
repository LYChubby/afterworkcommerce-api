<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    public function checkout(
        User $user,
        string $source,
        array $items,
        string $addressUuid,
        string $shippingQuoteUuid,
        ?string $customerNote = null
    ): Order {
        $variantUuids = collect($items)
            ->pluck('variant_uuid')
            ->all();

        if (
            empty($variantUuids) ||
            count($variantUuids) !== count(array_unique($variantUuids))
        ) {
            throw ValidationException::withMessages([
                'items' => 'Daftar variasi produk tidak valid atau berisi duplikat.',
            ]);
        }

        return DB::transaction(function () use (
            $user,
            $source,
            $items,
            $variantUuids,
            $addressUuid,
            $shippingQuoteUuid,
            $customerNote
        ) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $address = $lockedUser->addresses()
                ->where('uuid', $addressUuid)
                ->lockForUpdate()
                ->first();

            if (! $address) {
                throw ValidationException::withMessages([
                    'address_uuid' => 'Alamat pengiriman tidak ditemukan.',
                ]);
            }

            if ($source === 'direct') {
                $variants = ProductVariant::query()
                    ->whereIn('uuid', $variantUuids)
                    ->get()
                    ->keyBy('uuid');

                if ($variants->count() !== count($variantUuids)) {
                    throw ValidationException::withMessages([
                        'items' => 'Salah satu variasi produk tidak ditemukan.',
                    ]);
                }

                $orderItems = collect($items)
                    ->map(function ($item) use ($variants) {
                        return [
                            'product_variant_id' => $variants
                                ->get($item['variant_uuid'])->id,
                            'quantity' => (int) $item['quantity'],
                        ];
                    })
                    ->all();
            } elseif ($source === 'cart') {
                $cart = Cart::query()
                    ->where('user_id', $lockedUser->id)
                    ->lockForUpdate()
                    ->first();

                if (! $cart) {
                    throw ValidationException::withMessages([
                        'items' => 'Keranjang belanja kosong.',
                    ]);
                }

                $cartItems = CartItem::query()
                    ->where('cart_id', $cart->id)
                    ->whereHas('productVariant', function ($query) use ($variantUuids) {
                        $query->whereIn('uuid', $variantUuids);
                    })
                    ->with('productVariant')
                    ->lockForUpdate()
                    ->get();

                if ($cartItems->count() !== count($variantUuids)) {
                    throw ValidationException::withMessages([
                        'items' => 'Sebagian produk yang dipilih tidak ditemukan di keranjang.',
                    ]);
                }

                $orderItems = $cartItems
                    ->map(fn (CartItem $item) => [
                        'product_variant_id' => $item->product_variant_id,
                        'quantity' => $item->quantity,
                    ])
                    ->all();
            } else {
                throw ValidationException::withMessages([
                    'source' => 'Sumber pembelian tidak valid.',
                ]);
            }

            $order = $this->orderService->createPendingOrder(
                user: $lockedUser,
                items: $orderItems,
                shippingAddressSnapshot: [],
                shippingQuoteUuid: $shippingQuoteUuid,
                shippingAddressId: $address->id,
                customerNote: $customerNote
            );

            if ($source === 'cart') {
                CartItem::query()
                    ->where('cart_id', $cart->id)
                    ->whereIn(
                        'id',
                        $cartItems->pluck('id')->all()
                    )
                    ->delete();
            }

            return $order;
        }, 3);
    }
}
