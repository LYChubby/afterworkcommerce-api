<?php

use App\Models\Address;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Color;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingQuote;
use App\Models\Size;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customer = User::factory()->create([
        'role' => 'customer',
    ]);

    $this->warehouse = Warehouse::create([
        'name' => 'Checkout API Warehouse',
        'code' => 'ATL-CHECKOUT-API',
        'recipient_name' => 'ATELIER',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test No. 1',
        'province' => 'DI Yogyakarta',
        'city' => 'Yogyakarta',
        'postal_code' => '55225',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $category = Category::create([
        'name' => 'Checkout API Category',
        'slug' => 'checkout-api-'.Str::lower(Str::random(8)),
        'is_active' => true,
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Checkout API Product',
        'slug' => 'checkout-api-product-'.Str::lower(Str::random(8)),
        'description' => 'Product for checkout API tests.',
        'price' => 350000,
        'discount_price' => 300000,
        'is_active' => true,
    ]);

    $size = Size::create([
        'name' => 'M',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $color = Color::create([
        'name' => 'Black',
        'hex_code' => '#000000',
        'is_active' => true,
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'ATL-CHECKOUT-API',
        'stock_on_hand' => 10,
        'stock_reserved' => 0,
        'is_active' => true,
    ]);

    $this->address = Address::create([
        'user_id' => $this->customer->id,
        'label' => 'Rumah',
        'recipient_name' => 'Test Customer',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test No. 2',
        'province' => 'DI Yogyakarta',
        'city' => 'Yogyakarta',
        'district' => 'Gondokusuman',
        'subdistrict' => 'Baciro',
        'postal_code' => '55225',
        'country' => 'Indonesia',
        'notes' => null,
        'is_default' => true,
    ]);
});

function createCheckoutApiQuote(
    User $user,
    Warehouse $warehouse,
    ProductVariant $variant,
    int $quantity,
): ShippingQuote {
    return ShippingQuote::create([
        'quote_group_uuid' => (string) Str::uuid(),
        'user_id' => $user->id,
        'warehouse_id' => $warehouse->id,
        'origin_snapshot' => [
            'postal_code' => $warehouse->postal_code,
        ],
        'destination_snapshot' => [
            'postal_code' => '55225',
        ],
        'items_snapshot' => [
            [
                'variant_id' => $variant->id,
                'quantity' => $quantity,
            ],
        ],
        'courier_code' => 'jne',
        'courier_name' => 'JNE',
        'courier_service_code' => 'reg',
        'courier_service_name' => 'Regular',
        'description' => 'Checkout API test shipping',
        'duration' => '2 - 3 days',
        'service_type' => 'standard',
        'shipping_fee' => 15000,
        'currency' => 'IDR',
        'expires_at' => now()->addMinutes(15),
    ]);
}

it('rejects guests from checkout', function () {
    $this->postJson('/api/checkout', [])
        ->assertUnauthorized();
});

it('allows customers to create an order through direct checkout API', function () {
    $quote = createCheckoutApiQuote(
        $this->customer,
        $this->warehouse,
        $this->variant,
        2,
    );

    $response = $this->actingAs($this->customer, 'sanctum')
        ->postJson('/api/checkout', [
            'source' => 'direct',
            'items' => [
                [
                    'variant_uuid' => $this->variant->uuid,
                    'quantity' => 2,
                ],
            ],
            'address_uuid' => $this->address->uuid,
            'shipping_quote_uuid' => $quote->uuid,
            'customer_note' => 'Please handle with care.',
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.shipping_address.recipient_name', 'Test Customer')
        ->assertJsonPath('data.shipping_cost', 15000)
        ->assertJsonPath('data.currency', 'IDR');

    expect(Order::count())->toBe(1);

    $this->assertDatabaseHas('product_variants', [
        'id' => $this->variant->id,
        'stock_reserved' => 2,
    ]);
});

it('allows customers to create an order through cart checkout API', function () {
    $cart = $this->customer->cart()->create();

    $selectedItem = CartItem::create([
        'cart_id' => $cart->id,
        'product_variant_id' => $this->variant->id,
        'quantity' => 2,
    ]);

    $quote = createCheckoutApiQuote(
        $this->customer,
        $this->warehouse,
        $this->variant,
        2,
    );

    $response = $this->actingAs($this->customer, 'sanctum')
        ->postJson('/api/checkout', [
            'source' => 'cart',
            'items' => [
                [
                    'variant_uuid' => $this->variant->uuid,
                ],
            ],
            'address_uuid' => $this->address->uuid,
            'shipping_quote_uuid' => $quote->uuid,
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.items.0.quantity', 2);

    expect(Order::count())->toBe(1);

    $this->assertDatabaseMissing('cart_items', [
        'id' => $selectedItem->id,
    ]);
});

it('validates checkout request payload', function () {
    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/api/checkout', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'source',
            'items',
            'address_uuid',
            'shipping_quote_uuid',
        ]);
});

it('rejects quantity in cart checkout request', function () {
    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/api/checkout', [
            'source' => 'cart',
            'items' => [
                [
                    'variant_uuid' => $this->variant->uuid,
                    'quantity' => 2,
                ],
            ],
            'address_uuid' => $this->address->uuid,
            'shipping_quote_uuid' => fake()->uuid(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'items.0.quantity',
        ]);
});
