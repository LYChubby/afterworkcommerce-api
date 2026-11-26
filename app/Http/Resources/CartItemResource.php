<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variant = $this->productVariant;
        $product = $variant?->product;

        $unitPrice = (int) round((float) $product?->price);
        $effectiveUnitPrice = (int) round(
            (float) $product?->effective_price
        );

        $unitDiscount = $unitPrice - $effectiveUnitPrice;

        $primaryImage = $product?->images
            ->firstWhere('is_primary', true)
            ?? $product?->images
                ->sortBy('sort_order')
                ->first();

        return [
            'variant' => [
                'uuid' => $variant?->uuid,
                'sku' => $variant?->sku,
                'size' => [
                    'uuid' => $variant?->size?->uuid,
                    'name' => $variant?->size?->name,
                ],
                'color' => [
                    'uuid' => $variant?->color?->uuid,
                    'name' => $variant?->color?->name,
                    'hex_code' => $variant?->color?->hex_code,
                ],
            ],

            'product' => [
                'uuid' => $product?->uuid,
                'name' => $product?->name,
                'slug' => $product?->slug,
                'image' => $primaryImage
                    ? new ProductImageResource($primaryImage)
                    : null,
            ],

            'quantity' => (int) $this->quantity,

            'unit_price' => $unitPrice,
            'effective_unit_price' => $effectiveUnitPrice,

            'line_subtotal' => $unitPrice * $this->quantity,
            'line_discount' => $unitDiscount * $this->quantity,
            'line_total' => $effectiveUnitPrice * $this->quantity,
        ];
    }
}
