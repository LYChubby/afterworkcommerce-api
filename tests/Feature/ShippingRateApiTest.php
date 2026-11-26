<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingQuote;
use App\Models\Size;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'biteship.api_key' => 'biteship_test_fake_key',
        'biteship.base_url' => 'https://api.biteship.com/v1',
        'biteship.couriers' => ['jne', 'sicepat'],
    ]);

    $this->user = User::factory()->create();

    Warehouse::create([
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
        'sku' => 'TEST-SHIPPING-001',
        'stock_on_hand' => 100,
        'stock_reserved' => 0,
        'is_active' => true,
    ]);
});

it('returns shipping rates to authenticated customers', function () {
    Sanctum::actingAs($this->user);

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

    $response = $this->postJson('/api/shipping/rates', [
        'postal_code' => '40115',
        'items' => [
            [
                'variant_uuid' => $this->variant->uuid,
                'quantity' => 2,
            ],
        ],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.options.0.courier_code', 'jne')
        ->assertJsonPath('data.options.0.shipping_fee', 12000);

    expect(ShippingQuote::query()->count())->toBe(1);

    $quote = ShippingQuote::first();

    expect($quote->user_id)->toBe($this->user->id)
        ->and($quote->shipping_fee)->toBe(12000)
        ->and($quote->expires_at->isFuture())->toBeTrue()
        ->and($quote->order_id)->toBeNull();
});

it('rejects unauthenticated shipping rate requests', function () {
    Http::fake();

    $response = $this->postJson('/api/shipping/rates', [
        'postal_code' => '40115',
        'items' => [
            [
                'variant_uuid' => $this->variant->uuid,
                'quantity' => 1,
            ],
        ],
    ]);

    $response->assertUnauthorized();

    Http::assertNothingSent();
});

it('rejects invalid shipping rate request data', function () {
    Sanctum::actingAs($this->user);

    Http::fake();

    $response = $this->postJson('/api/shipping/rates', [
        'postal_code' => '123',
        'items' => [],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'postal_code',
            'items',
        ]);

    Http::assertNothingSent();
});

it('returns empty options when no courier rates are available', function () {
    Sanctum::actingAs($this->user);

    Http::fake([
        'api.biteship.com/v1/rates/couriers' => Http::response([
            'success' => true,
            'pricing' => [],
        ], 200),
    ]);

    $response = $this->postJson('/api/shipping/rates', [
        'postal_code' => '40115',
        'items' => [
            [
                'variant_uuid' => $this->variant->uuid,
                'quantity' => 1,
            ],
        ],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.options', []);

    expect(ShippingQuote::query()->count())->toBe(0);
});
