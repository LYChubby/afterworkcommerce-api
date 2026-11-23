<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'description' => $this->description,

            'price' => $this->price,
            'discount_price' => $this->discount_price,
            'effective_price' => $this->effective_price,
            'is_on_sale' => $this->is_on_sale,

            'weight_grams' => $this->weight_grams,

            'dimensions' => [
                'length_cm' => $this->length_cm,
                'width_cm' => $this->width_cm,
                'height_cm' => $this->height_cm,
            ],

            'category' => new CategoryResource(
                $this->whenLoaded('category')
            ),

            'images' => ProductImageResource::collection(
                $this->whenLoaded('images')
            ),

            'variants' => ProductVariantResource::collection(
                $this->whenLoaded('variants')
            ),

            'total_available_stock' => $this->whenLoaded(
                'variants',
                fn () => $this->variants->sum(
                    fn ($variant) => $variant->available_stock
                )
            ),

            'created_at' => $this->created_at,
        ];
    }
}
