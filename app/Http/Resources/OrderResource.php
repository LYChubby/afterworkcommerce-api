<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,

            'items' => $this->items->map(fn ($item) => [
                'uuid' => $item->uuid,
                'product_name' => $item->product_name,
                'product_slug' => $item->product_slug,
                'sku' => $item->sku,
                'size_name' => $item->size_name,
                'color_name' => $item->color_name,
                'color_hex_code' => $item->color_hex_code,
                'unit_price' => (float) $item->unit_price,
                'unit_discount' => (float) $item->unit_discount,
                'quantity' => $item->quantity,
                'line_total' => (float) $item->line_total,
            ]),

            'shipping_address' => $this->shipping_address_snapshot,
            'shipping' => $this->shipping_courier_snapshot,

            'subtotal' => (float) $this->subtotal,
            'item_discount_total' => (float) $this->item_discount_total,
            'shipping_cost' => (float) $this->shipping_cost,
            'grand_total' => (float) $this->grand_total,
            'currency' => $this->currency,

            'payment_deadline_at' => $this->payment_deadline_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
