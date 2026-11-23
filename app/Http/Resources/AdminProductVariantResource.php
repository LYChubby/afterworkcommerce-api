<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductVariantResource extends JsonResource
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

            'stock_on_hand' => $this->stock_on_hand,
            'stock_reserved' => $this->stock_reserved,
            'available_stock' => $this->available_stock,

            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
