<?php

namespace App\Services;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductVariantService
{
    public function getProductVariants(Product $product)
    {
        return $product->variants()
            ->with(['size', 'color'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function getVariantByUuid(
        Product $product,
        string $uuid
    ): ProductVariant {
        return $product->variants()
            ->with(['size', 'color'])
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function createVariant(
        Product $product,
        array $data
    ): ProductVariant {
        return DB::transaction(function () use ($product, $data) {
            $size = Size::query()
                ->where('uuid', $data['size_uuid'])
                ->where('is_active', true)
                ->firstOrFail();

            $color = Color::query()
                ->where('uuid', $data['color_uuid'])
                ->where('is_active', true)
                ->firstOrFail();

            $variant = $product->variants()->create([
                'size_id' => $size->id,
                'color_id' => $color->id,
                'sku' => $data['sku'],
                'stock_on_hand' => 0,
                'stock_reserved' => 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $variant->load(['size', 'color']);
        });
    }

    public function updateVariant(
        ProductVariant $variant,
        array $data
    ): ProductVariant {
        return DB::transaction(function () use ($variant, $data) {
            $variant = ProductVariant::query()
                ->whereKey($variant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $size = null;
            $color = null;

            if (array_key_exists('size_uuid', $data)) {
                $size = Size::query()
                    ->where('uuid', $data['size_uuid'])
                    ->where('is_active', true)
                    ->firstOrFail();
            }

            if (array_key_exists('color_uuid', $data)) {
                $color = Color::query()
                    ->where('uuid', $data['color_uuid'])
                    ->where('is_active', true)
                    ->firstOrFail();
            }

            $newSizeId = $size?->id ?? $variant->size_id;
            $newColorId = $color?->id ?? $variant->color_id;

            $combinationChanged =
                $newSizeId !== $variant->size_id ||
                $newColorId !== $variant->color_id;

            if ($combinationChanged) {
                $hasStock = $variant->stock_on_hand > 0
                    || $variant->stock_reserved > 0;

                $hasInventoryHistory = $variant->inventoryMovements()->exists();

                $hasReservations = $variant->inventoryReservations()->exists();

                if ($hasStock || $hasInventoryHistory || $hasReservations) {
                    throw ValidationException::withMessages([
                        'variant' => [
                            'Kombinasi ukuran dan warna tidak dapat diubah karena varian masih memiliki stok atau sudah memiliki riwayat inventaris dan reservasi.',
                        ],
                    ]);
                }
            }

            $variant->update([
                'size_id' => $newSizeId,
                'color_id' => $newColorId,
                'sku' => $data['sku'] ?? $variant->sku,
                'is_active' => $data['is_active'] ?? $variant->is_active,
            ]);

            return $variant->refresh()->load(['size', 'color']);
        });
    }

    public function deleteVariant(ProductVariant $variant): void
    {
        DB::transaction(function () use ($variant): void {
            $variant = ProductVariant::query()
                ->whereKey($variant->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasStock = $variant->stock_on_hand > 0
                || $variant->stock_reserved > 0;

            $hasInventoryHistory = $variant
                ->inventoryMovements()
                ->exists();

            $hasReservations = $variant
                ->inventoryReservations()
                ->exists();

            if ($hasStock || $hasInventoryHistory || $hasReservations) {
                throw ValidationException::withMessages([
                    'variant' => [
                        'Varian tidak dapat dihapus karena masih memiliki stok atau riwayat inventaris dan reservasi. Nonaktifkan varian jika tidak ingin digunakan.',
                    ],
                ]);
            }

            $variant->delete();
        });
    }
}
