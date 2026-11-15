<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'recipient_name',
        'phone',
        'address_line',
        'province',
        'city',
        'district',
        'postal_code',
        'country',
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
        static::creating(function (Warehouse $warehouse) {
            if (empty($warehouse->uuid)) {
                $warehouse->uuid = (string) Str::uuid();
            }
        });
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
