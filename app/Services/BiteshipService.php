<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BiteshipService
{
    /**
     * Mengambil pilihan ongkos kirim dari Biteship.
     *
     * $cartItems:
     * [
     *     ['variant_id' => 1, 'quantity' => 2],
     *     ['variant_id' => 2, 'quantity' => 1],
     * ]
     */
    public function getRates(
        array $destination,
        array $cartItems
    ): array {
        $apiKey = config('biteship.api_key');

        if (empty($apiKey)) {
            throw new RuntimeException(
                'Biteship API key belum dikonfigurasi.'
            );
        }

        if (empty(config('biteship.couriers'))) {
            throw new RuntimeException(
                'Daftar kurir Biteship belum dikonfigurasi.'
            );
        }

        $destinationPostalCode = $destination['postal_code'] ?? null;

        if (
            ! is_string($destinationPostalCode)
            && ! is_numeric($destinationPostalCode)
        ) {
            throw ValidationException::withMessages([
                'postal_code' => 'Kode pos tujuan wajib diisi.',
            ]);
        }

        $destinationPostalCode = trim(
            (string) $destinationPostalCode
        );

        if (! preg_match('/^\d{5}$/', $destinationPostalCode)) {
            throw ValidationException::withMessages([
                'postal_code' => 'Kode pos harus terdiri dari 5 digit.',
            ]);
        }

        if (empty($cartItems)) {
            throw ValidationException::withMessages([
                'items' => 'Keranjang belanja tidak boleh kosong.',
            ]);
        }

        $warehouse = Warehouse::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $warehouse) {
            throw new RuntimeException(
                'Gudang aktif ATELIER tidak ditemukan.'
            );
        }

        if (
            ! preg_match('/^\d{5}$/', (string) $warehouse->postal_code)
        ) {
            throw new RuntimeException(
                'Kode pos gudang belum valid.'
            );
        }

        $normalizedItems = $this->normalizeCartItems($cartItems);

        $variants = ProductVariant::query()
            ->with(['product', 'size', 'color'])
            ->whereIn('id', array_keys($normalizedItems))
            ->get()
            ->keyBy('id');

        if ($variants->count() !== count($normalizedItems)) {
            throw ValidationException::withMessages([
                'items' => 'Satu atau lebih variasi produk tidak ditemukan.',
            ]);
        }

        $items = [];

        foreach ($normalizedItems as $variantId => $quantity) {
            $variant = $variants->get($variantId);
            $product = $variant->product;

            if (
                ! $variant->is_active
                || ! $product
                || ! $product->is_active
                || ! $variant->size?->is_active
                || ! $variant->color?->is_active
            ) {
                throw ValidationException::withMessages([
                    'items' => 'Terdapat produk yang tidak aktif.',
                ]);
            }

            if (
                $product->weight_grams === null
                || $product->weight_grams <= 0
            ) {
                throw ValidationException::withMessages([
                    'items' => "Berat produk {$product->name} belum diatur.",
                ]);
            }

            $item = [
                'name' => $product->name,
                'description' => implode(' - ', [
                    $variant->size->name,
                    $variant->color->name,
                ]),
                'category' => 'fashion',
                'sku' => $variant->sku,
                'value' => (int) round(
                    (float) $product->effective_price
                ),
                'quantity' => $quantity,
                'weight' => $product->weight_grams,
            ];

            if (
                $product->length_cm
                && $product->width_cm
                && $product->height_cm
            ) {
                $item['length'] = $product->length_cm;
                $item['width'] = $product->width_cm;
                $item['height'] = $product->height_cm;
            }

            $items[] = $item;
        }

        $payload = [
            'origin_postal_code' => (int) $warehouse->postal_code,
            'destination_postal_code' => (int) $destinationPostalCode,
            'couriers' => implode(
                ',',
                config('biteship.couriers')
            ),
            'items' => $items,
        ];

        $response = Http::acceptJson()
            ->withHeaders([
                'Authorization' => $apiKey,
            ])
            ->timeout(config('biteship.timeout', 20))
            ->post(
                rtrim(config('biteship.base_url'), '/')
                    . '/rates/couriers',
                $payload
            );

        if ($response->failed()) {
            throw new RuntimeException(
                'Gagal mengambil tarif Biteship: '
                    . $response->body()
            );
        }

        $responseData = $response->json();

        if (
            ! is_array($responseData)
            || ! ($responseData['success'] ?? false)
            || ! is_array($responseData['pricing'] ?? null)
        ) {
            throw new RuntimeException(
                'Respons tarif Biteship tidak valid.'
            );
        }

        return [
            'origin' => $responseData['origin'] ?? null,
            'destination' => $responseData['destination'] ?? null,
            'pricing' => collect($responseData['pricing'])
                ->map(function (array $rate): array {
                    return [
                        'courier_code' => $rate['courier_code'] ?? null,
                        'courier_name' => $rate['courier_name'] ?? null,
                        'courier_service_code' =>
                        $rate['courier_service_code'] ?? null,
                        'courier_service_name' =>
                        $rate['courier_service_name'] ?? null,
                        'description' => $rate['description'] ?? null,
                        'duration' => $rate['duration'] ?? null,
                        'service_type' => $rate['service_type'] ?? null,
                        'shipping_fee' => (int) (
                            $rate['price'] ?? 0
                        ),
                        'currency' => $rate['currency'] ?? 'IDR',
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * Validasi dan gabungkan variant ID yang duplikat.
     */
    private function normalizeCartItems(array $cartItems): array
    {
        $normalized = [];

        foreach ($cartItems as $item) {
            $variantId = filter_var(
                $item['variant_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $quantity = filter_var(
                $item['quantity'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                ! $variantId
                || ! $quantity
                || $variantId <= 0
                || $quantity <= 0
            ) {
                throw ValidationException::withMessages([
                    'items' => 'ID variasi dan jumlah produk harus valid.',
                ]);
            }

            $normalized[$variantId] =
                ($normalized[$variantId] ?? 0) + $quantity;
        }

        return $normalized;
    }
}
