<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartService
{
    /**
     * Mengambil Cart milik user.
     * Cart dibuat otomatis jika belum tersedia.
     */
    public function getCart(User $user): Cart
    {
        return DB::transaction(function () use ($user) {
            $cart = $this->lockUserCart($user);

            return $this->loadCart($cart);
        }, 3);
    }

    /**
     * Menambahkan varian ke Cart.
     * Jika varian sudah ada, kuantitas akan ditambahkan.
     */
    public function addItem(
        User $user,
        string $variantUuid,
        int $quantity
    ): Cart {
        $this->validateQuantity($quantity);

        return DB::transaction(function () use (
            $user,
            $variantUuid,
            $quantity
        ) {
            $cart = $this->lockUserCart($user);

            $variant = $this->findPurchasableVariant($variantUuid);

            $item = $cart->items()
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            $newQuantity = ($item?->quantity ?? 0) + $quantity;

            $this->assertAvailableStock($variant, $newQuantity);

            if ($item) {
                $item->update([
                    'quantity' => $newQuantity,
                ]);
            } else {
                $cart->items()->create([
                    'product_variant_id' => $variant->id,
                    'quantity' => $quantity,
                ]);
            }

            return $this->loadCart($cart);
        }, 3);
    }

    /**
     * Mengubah kuantitas item berdasarkan UUID varian.
     */
    public function updateItem(
        User $user,
        string $variantUuid,
        int $quantity
    ): Cart {
        $this->validateQuantity($quantity);

        return DB::transaction(function () use (
            $user,
            $variantUuid,
            $quantity
        ) {
            $cart = $this->lockUserCart($user);

            $variant = $this->findPurchasableVariant($variantUuid);

            $this->assertAvailableStock($variant, $quantity);

            $item = $cart->items()
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $item->update([
                'quantity' => $quantity,
            ]);

            return $this->loadCart($cart);
        }, 3);
    }

    /**
     * Menghapus item berdasarkan UUID varian.
     */
    public function removeItem(
        User $user,
        string $variantUuid
    ): Cart {
        return DB::transaction(function () use ($user, $variantUuid) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $cart = $lockedUser->cart()
                ->lockForUpdate()
                ->firstOrFail();

            $item = $cart->items()
                ->whereHas('productVariant', function ($query) use (
                    $variantUuid
                ) {
                    $query->where('uuid', $variantUuid);
                })
                ->lockForUpdate()
                ->firstOrFail();

            $item->delete();

            return $this->loadCart($cart);
        }, 3);
    }

    /**
     * Mengunci user dan Cart.
     * Lock user mencegah dua proses membuat Cart bersamaan.
     */
    private function lockUserCart(User $user): Cart
    {
        $lockedUser = User::query()
            ->whereKey($user->id)
            ->lockForUpdate()
            ->firstOrFail();

        $cart = $lockedUser->cart()
            ->lockForUpdate()
            ->first();

        if (! $cart) {
            $cart = $lockedUser->cart()->create();
        }

        return $cart;
    }

    /**
     * Mengambil varian yang masih dapat digunakan pelanggan.
     */
    private function findPurchasableVariant(
        string $variantUuid
    ): ProductVariant {
        $variant = ProductVariant::query()
            ->with(['product', 'size', 'color'])
            ->where('uuid', $variantUuid)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            ! $variant->is_active
            || ! $variant->product?->is_active
            || ! $variant->size?->is_active
            || ! $variant->color?->is_active
        ) {
            throw ValidationException::withMessages([
                'variant_uuid' => [
                    'Varian produk sudah tidak tersedia.',
                ],
            ]);
        }

        return $variant;
    }

    /**
     * Memastikan kuantitas tidak melebihi stok tersedia.
     */
    private function assertAvailableStock(
        ProductVariant $variant,
        int $quantity
    ): void {
        $availableStock = (int) $variant->available_stock;

        if ($quantity > $availableStock) {
            throw ValidationException::withMessages([
                'quantity' => [
                    'Jumlah produk melebihi stok yang tersedia. Stok tersedia: '
                        .$availableStock.'.',
                ],
            ]);
        }
    }

    /**
     * Memastikan kuantitas selalu positif.
     */
    private function validateQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => [
                    'Jumlah produk minimal 1.',
                ],
            ]);
        }
    }

    /**
     * Memuat relasi yang dibutuhkan untuk menampilkan Cart.
     */
    private function loadCart(Cart $cart): Cart
    {
        return $cart->load([
            'items.productVariant.product.images',
            'items.productVariant.size',
            'items.productVariant.color',
        ]);
    }
}
