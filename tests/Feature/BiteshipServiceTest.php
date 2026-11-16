<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Warehouse;
use App\Services\BiteshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'biteship.api_key' => 'biteship_test_fake_key',
        'biteship.base_url' => 'https://api.biteship.com/v1',
        'biteship.couriers' => ['jne', 'sicepat'],
    ]);

    $warehouse = Warehouse::create([
        'name' => 'Test Warehouse',
        'code' => 'TEST-WH',
        'recipient_name' => 'ATELIER',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test No. 1',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'district' => 'Coblong',
        'postal_code' => '40111',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $category = Category::create([
        'name' => 'Test Category',
        'slug' => 'test-category',
        'description' => 'Test category',
        'is_active' => true,
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Test Shirt',
        'slug' => 'test-shirt',
        'description' => 'Test product',
        'price' => 350000,
        'discount_price' => 299000,
        'weight_grams' => 500,
        'length_cm' => 30,
        'width_cm' => 25,
        'height_cm' => 5,
        'is_active' => true,
    ]);

    $size = Size::create([
        'name' => 'Test M',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $color = Color::create([
        'name' => 'Test Black',
        'hex_code' => '#000000',
        'is_active' => true,
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'TEST-BITESHIP-001',
        'stock_on_hand' => 100,
        'stock_reserved' => 0,
        'is_active' => true,
    ]);
});

it('retrieves courier rates using database product data', function () {
    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'origin' => [
                'postal_code' => 40111,
            ],
            'destination' => [
                'postal_code' => 40115,
            ],
            'pricing' => [
                [
                    'courier_code' => 'jne',
                    'courier_name' => 'JNE',
                    'courier_service_code' => 'reg',
                    'courier_service_name' => 'Regular',
                    'description' => 'Regular service',
                    'duration' => '2 - 3 days',
                    'service_type' => 'standard',
                    'shipping_fee' => 10000,
                    'price' => 12000,
                    'currency' => 'IDR',
                ],
            ],
        ], 200),
    ]);

    $result = app(BiteshipService::class)->getRates(
        ['postal_code' => '40115'],
        [
            [
                'variant_id' => $this->variant->id,
                'quantity' => 2,
            ],
        ]
    );

    expect($result['pricing'])->toHaveCount(1)
        ->and($result['pricing'][0]['shipping_fee'])->toBe(12000);

    Http::assertSent(function ($request) {
        $payload = $request->data();

        return $request->url()
            === 'https://api.biteship.com/v1/rates/couriers'
            && $request->hasHeader(
                'Authorization',
                'biteship_test_fake_key'
            )
            && $payload['origin_postal_code'] === 40111
            && $payload['destination_postal_code'] === 40115
            && $payload['items'][0]['value'] === 299000
            && $payload['items'][0]['weight'] === 500
            && $payload['items'][0]['quantity'] === 2;
    });
});

it('rejects destination postal code with invalid format', function () {
    Http::fake();

    expect(
        fn() => app(BiteshipService::class)->getRates(
            ['postal_code' => '123'],
            [
                [
                    'variant_id' => $this->variant->id,
                    'quantity' => 1,
                ],
            ]
        )
    )->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

it('rejects products without weight', function () {
    $this->variant->product->update([
        'weight_grams' => null,
    ]);

    Http::fake();

    expect(
        fn() => app(BiteshipService::class)->getRates(
            ['postal_code' => '40115'],
            [
                [
                    'variant_id' => $this->variant->id,
                    'quantity' => 1,
                ],
            ]
        )
    )->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

it('merges duplicate variants before requesting rates', function () {
    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => [],
        ], 200),
    ]);

    app(BiteshipService::class)->getRates(
        ['postal_code' => '40115'],
        [
            [
                'variant_id' => $this->variant->id,
                'quantity' => 1,
            ],
            [
                'variant_id' => $this->variant->id,
                'quantity' => 2,
            ],
        ]
    );

    Http::assertSent(function ($request) {
        return $request->data()['items'][0]['quantity'] === 3;
    });
});
