<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'warehouse_id',
        'product_variant_id',
        'movement_type',
        'quantity',
        'stock_on_hand_before',
        'stock_reserved_before',
        'stock_on_hand_after',
        'stock_reserved_after',
        'reference_type',
        'reference_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'stock_on_hand_before' => 'integer',
            'stock_reserved_before' => 'integer',
            'stock_on_hand_after' => 'integer',
            'stock_reserved_after' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InventoryMovement $movement) {
            if (empty($movement->uuid)) {
                $movement->uuid = (string) Str::uuid();
            }
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
