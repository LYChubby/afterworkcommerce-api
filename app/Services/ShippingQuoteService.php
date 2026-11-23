<?php

namespace App\Services;

use App\Models\ShippingQuote;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShippingQuoteService
{
    public function __construct(
        protected BiteshipService $biteshipService
    ) {}

    public function createQuotes(
        User $user,
        string $postalCode,
        array $items
    ): array {
        $warehouse = Warehouse::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->firstOrFail();

        $rates = $this->biteshipService->getRates(
            ['postal_code' => $postalCode],
            $items
        );

        $pricing = $rates['pricing'] ?? [];

        if (empty($pricing)) {
            return [
                'quote_group_uuid' => null,
                'expires_at' => null,
                'options' => [],
            ];
        }

        $groupUuid = (string) Str::uuid();
        $expiresAt = now()->addMinutes(15);

        $normalizedItems = $this->normalizeItems($items);

        $quotes = DB::transaction(function () use (
            $user,
            $warehouse,
            $rates,
            $pricing,
            $groupUuid,
            $expiresAt,
            $postalCode,
            $normalizedItems
        ) {
            $created = [];

            foreach ($pricing as $rate) {
                $fee = $rate['shipping_fee'] ?? null;

                if (
                    ! is_numeric($fee)
                    || (int) $fee < 0
                    || ($rate['currency'] ?? 'IDR') !== 'IDR'
                    || empty($rate['courier_code'])
                ) {
                    continue;
                }

                $quote = ShippingQuote::create([
                    'quote_group_uuid' => $groupUuid,
                    'user_id' => $user->id,
                    'warehouse_id' => $warehouse->id,

                    'origin_snapshot' => $rates['origin'] ?? [
                        'postal_code' => $warehouse->postal_code,
                    ],

                    'destination_snapshot' => array_merge(
                        is_array($rates['destination'] ?? null)
                            ? $rates['destination']
                            : [],
                        [
                            'postal_code' => $postalCode,
                        ]
                    ),

                    'items_snapshot' => $normalizedItems,

                    'courier_code' => $rate['courier_code'],
                    'courier_name' => $rate['courier_name'] ?? '',
                    'courier_service_code' => $rate['courier_service_code'] ?? null,
                    'courier_service_name' => $rate['courier_service_name'] ?? null,
                    'description' => $rate['description'] ?? null,
                    'duration' => $rate['duration'] ?? null,
                    'service_type' => $rate['service_type'] ?? null,

                    'shipping_fee' => (int) $fee,
                    'currency' => 'IDR',
                    'expires_at' => $expiresAt,
                ]);

                $created[] = $quote;
            }

            return $created;
        });

        return [
            'quote_group_uuid' => $groupUuid,
            'expires_at' => $expiresAt->toISOString(),

            'options' => collect($quotes)
                ->map(fn (ShippingQuote $quote) => [
                    'quote_uuid' => $quote->uuid,
                    'courier_code' => $quote->courier_code,
                    'courier_name' => $quote->courier_name,
                    'courier_service_code' => $quote->courier_service_code,
                    'courier_service_name' => $quote->courier_service_name,
                    'description' => $quote->description,
                    'duration' => $quote->duration,
                    'shipping_fee' => $quote->shipping_fee,
                    'currency' => $quote->currency,
                    'expires_at' => $quote->expires_at->toISOString(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $variantId = (int) $item['variant_id'];
            $quantity = (int) $item['quantity'];

            $normalized[$variantId] =
                ($normalized[$variantId] ?? 0) + $quantity;
        }

        ksort($normalized);

        return collect($normalized)
            ->map(fn ($quantity, $variantId) => [
                'variant_id' => (int) $variantId,
                'quantity' => $quantity,
            ])
            ->values()
            ->all();
    }
}
