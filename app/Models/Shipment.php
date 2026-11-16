<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Shipment extends Model
{
    protected $fillable = [
        'uuid',
        'order_id',
        'provider',
        'provider_order_id',
        'tracking_id',
        'waybill_id',
        'courier_code',
        'courier_service_code',
        'courier_name',
        'status',
        'tracking_url',
        'actual_shipping_fee',
        'provider_response',
        'shipped_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'actual_shipping_fee' => 'integer',
            'provider_response' => 'array',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Shipment $shipment) {
            if (empty($shipment->uuid)) {
                $shipment->uuid = (string) Str::uuid();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }
}
