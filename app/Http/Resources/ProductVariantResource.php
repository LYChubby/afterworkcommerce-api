<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'sku' => $this->sku,

            'size' => [
                'uuid' => $this->size?->uuid,
                'name' => $this->size?->name,
            ],

            'color' => [
                'uuid' => $this->color?->uuid,
                'name' => $this->color?->name,
                'hex_code' => $this->color?->hex_code,
            ],

            'available_stock' => $this->available_stock,
            'is_available' => $this->available_stock > 0,
        ];
    }
}
