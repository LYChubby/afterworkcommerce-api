<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'product_id',
        'size_id',
        'color_id',
        'sku',
        'stock_on_hand',
        'stock_reserved',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stock_on_hand' => 'integer',
            'stock_reserved' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant) {
            if (empty($variant->uuid)) {
                $variant->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Varian dimiliki oleh satu produk.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Varian memiliki satu ukuran.
     */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    /**
     * Varian memiliki satu warna.
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /**
     * Menghitung stok yang tersedia untuk dibeli.
     */
    protected function availableStock(): Attribute
    {
        return Attribute::get(
            fn() => max(
                0,
                $this->stock_on_hand - $this->stock_reserved
            )
        );
    }
}
