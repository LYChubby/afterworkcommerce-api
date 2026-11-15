<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InventoryReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'order_id',
        'order_item_id',
        'product_variant_id',
        'quantity',
        'status',
        'expires_at',
        'confirmed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InventoryReservation $reservation) {
            if (empty($reservation->uuid)) {
                $reservation->uuid = (string) Str::uuid();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
