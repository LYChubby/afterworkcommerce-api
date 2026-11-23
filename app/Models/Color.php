<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Color extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'hex_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Color $color) {
            if (empty($color->uuid)) {
                $color->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Satu warna dapat digunakan oleh banyak varian produk.
     */
    public function productVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
