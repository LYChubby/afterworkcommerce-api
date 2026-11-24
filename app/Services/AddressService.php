<?php

namespace App\Services;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AddressService
{
    /**
     * Get all addresses owned by the user.
     */
    public function getAddresses(User $user): Collection
    {
        return $user->addresses()
            ->orderByDesc('is_default')
            ->latest()
            ->get();
    }

    /**
     * Get an address owned by the user.
     */
    public function getAddressByUuid(User $user, string $uuid): Address
    {
        return $user->addresses()
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * Create a new address.
     */
    public function createAddress(User $user, array $data): Address
    {
        return DB::transaction(function () use ($user, $data) {
            User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasAddresses = $user->addresses()->exists();
            $isDefault = $data['is_default'] ?? false;

            if (! $hasAddresses || $isDefault) {
                $isDefault = true;

                $user->addresses()
                    ->update(['is_default' => false]);
            }

            $data['is_default'] = $isDefault;

            return $user->addresses()->create($data);
        });
    }

    /**
     * Update an address owned by the user.
     */
    public function updateAddress(
        User $user,
        string $uuid,
        array $data
    ): Address {
        return DB::transaction(function () use ($user, $uuid, $data) {
            User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $address = $user->addresses()
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();

            $wasDefault = $address->is_default;
            $requestedDefault = $data['is_default'] ?? null;

            if ($requestedDefault === true) {
                $user->addresses()
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->update($data);

            if ($wasDefault && $requestedDefault === false) {
                $nextAddress = $user->addresses()
                    ->where('id', '!=', $address->id)
                    ->latest()
                    ->first();

                if ($nextAddress) {
                    $nextAddress->update(['is_default' => true]);
                } else {
                    $address->update(['is_default' => true]);
                }
            }

            return $address->refresh();
        });
    }

    /**
     * Delete an address owned by the user.
     */
    public function deleteAddress(User $user, string $uuid): void
    {
        DB::transaction(function () use ($user, $uuid) {
            User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $address = $user->addresses()
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();

            $wasDefault = $address->is_default;

            $address->delete();

            if ($wasDefault) {
                $nextAddress = $user->addresses()
                    ->latest()
                    ->first();

                $nextAddress?->update(['is_default' => true]);
            }
        });
    }
}
