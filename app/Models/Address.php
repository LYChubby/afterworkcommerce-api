<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Address extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'label',
        'recipient_name',
        'phone',
        'address_line',
        'province',
        'city',
        'district',
        'subdistrict',
        'postal_code',
        'country',
        'notes',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Address $address) {
            if (empty($address->uuid)) {
                $address->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
