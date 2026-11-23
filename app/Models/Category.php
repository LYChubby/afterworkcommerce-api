<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'description',
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
        static::creating(function (Category $category) {
            if (empty($category->uuid)) {
                $category->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Satu kategori memiliki banyak produk.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
