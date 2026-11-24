<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SizeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'product_variants_count' => $this->whenCounted('productVariants'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
