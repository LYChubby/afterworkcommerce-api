<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subtotal = 0;
        $discountTotal = 0;
        $totalQuantity = 0;

        foreach ($this->items as $item) {
            $product = $item->productVariant?->product;

            $quantity = (int) $item->quantity;

            $unitPrice = (int) round(
                (float) $product?->price
            );

            $effectiveUnitPrice = (int) round(
                (float) $product?->effective_price
            );

            $subtotal += $unitPrice * $quantity;

            $discountTotal += (
                $unitPrice - $effectiveUnitPrice
            ) * $quantity;

            $totalQuantity += $quantity;
        }

        return [
            'uuid' => $this->uuid,

            'items' => CartItemResource::collection(
                $this->whenLoaded('items')
            ),

            'summary' => [
                'items_count' => $this->items->count(),
                'total_quantity' => $totalQuantity,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'total' => $subtotal - $discountTotal,
            ],

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
