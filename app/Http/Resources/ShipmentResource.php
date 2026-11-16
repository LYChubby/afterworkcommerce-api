<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'provider' => $this->provider,
            'courier_name' => $this->courier_name,
            'courier_service_name' => $this->courier_service_name,
            'tracking_id' => $this->tracking_id,
            'waybill_id' => $this->waybill_id,
            'tracking_url' => $this->tracking_url,
            'status' => $this->status,
            'shipped_at' => $this->shipped_at,
            'delivered_at' => $this->delivered_at,
        ];
    }
}
