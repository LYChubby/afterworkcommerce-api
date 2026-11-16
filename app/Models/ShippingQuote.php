<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShippingQuote extends Model
{
    protected $fillable = [
        'uuid',
        'quote_group_uuid',
        'user_id',
        'warehouse_id',
        'origin_snapshot',
        'destination_snapshot',
        'items_snapshot',
        'courier_code',
        'courier_name',
        'courier_service_code',
        'courier_service_name',
        'description',
        'duration',
        'service_type',
        'shipping_fee',
        'currency',
        'expires_at',
        'used_at',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'origin_snapshot' => 'array',
            'destination_snapshot' => 'array',
            'items_snapshot' => 'array',
            'shipping_fee' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShippingQuote $quote) {
            $quote->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isUsable(): bool
    {
        return $this->order_id === null
            && $this->used_at === null
            && $this->expires_at->isFuture();
    }
}
