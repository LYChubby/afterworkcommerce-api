<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use Illuminate\Support\Str;

const CUSTOMER_CART_ENDPOINT = '/api/cart';

function createCartTestCustomer(): User
{
    return User::factory()->create([
        'role' => 'customer',
    ]);
}

function createCartTestVariant(
    array $variantAttributes = [],
    array $productAttributes = []
): ProductVariant {
    $category = Category::create([
        'name' => 'Cart Category',
        'slug' => 'cart-category-'.Str::lower(Str::random(8)),
        'is_active' => true,
    ]);

    $product = Product::create(array_merge([
        'category_id' => $category->id,
        'name' => 'Cart Test Product',
        'slug' => 'cart-product-'.Str::lower(Str::random(8)),
        'description' => 'Product for cart tests.',
        'price' => 350000,
        'discount_price' => 300000,
        'is_active' => true,
    ], $productAttributes));

    $size = Size::create([
        'name' => 'Size '.Str::upper(Str::random(6)),
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $color = Color::create([
        'name' => 'Color '.Str::upper(Str::random(6)),
        'hex_code' => '#112233',
        'is_active' => true,
    ]);

    return ProductVariant::create(array_merge([
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'SKU-'.Str::upper(Str::random(10)),
        'stock_on_hand' => 10,
        'stock_reserved' => 0,
        'is_active' => true,
    ], $variantAttributes));
}

it('rejects guests from all customer cart endpoints', function () {
    $variant = createCartTestVariant();

    $this->getJson(CUSTOMER_CART_ENDPOINT)
        ->assertUnauthorized();

    $this->postJson(CUSTOMER_CART_ENDPOINT.'/items', [
        'variant_uuid' => $variant->uuid,
        'quantity' => 1,
    ])->assertUnauthorized();

    $this->patchJson(
        CUSTOMER_CART_ENDPOINT.'/items/'.$variant->uuid,
        ['quantity' => 2]
    )->assertUnauthorized();

    $this->deleteJson(
        CUSTOMER_CART_ENDPOINT.'/items/'.$variant->uuid
    )->assertUnauthorized();
});

it('allows customers to retrieve only their own cart', function () {
    $customer = createCartTestCustomer();
    $otherCustomer = createCartTestCustomer();

    $this->actingAs($customer, 'sanctum')
        ->getJson(CUSTOMER_CART_ENDPOINT)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.summary.items_count', 0)
        ->assertJsonPath('data.summary.total_quantity', 0)
        ->assertJsonPath('data.summary.total', 0);

    $this->assertDatabaseHas('carts', [
        'user_id' => $customer->id,
    ]);

    $this->assertDatabaseMissing('carts', [
        'user_id' => $otherCustomer->id,
    ]);
});

it('allows customers to add items and merges the same variant quantity', function () {
    $customer = createCartTestCustomer();

    $variant = createCartTestVariant([
        'stock_on_hand' => 10,
    ]);

    $this->actingAs($customer, 'sanctum');

    $this->postJson(CUSTOMER_CART_ENDPOINT.'/items', [
        'variant_uuid' => $variant->uuid,
        'quantity' => 1,
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.items.0.variant.uuid', $variant->uuid)
        ->assertJsonPath('data.items.0.quantity', 1);

    $this->postJson(CUSTOMER_CART_ENDPOINT.'/items', [
        'variant_uuid' => $variant->uuid,
        'quantity' => 1,
    ])
        ->assertCreated()
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.unit_price', 350000)
        ->assertJsonPath('data.items.0.effective_unit_price', 300000)
        ->assertJsonPath('data.items.0.line_subtotal', 700000)
        ->assertJsonPath('data.items.0.line_discount', 100000)
        ->assertJsonPath('data.items.0.line_total', 600000)
        ->assertJsonPath('data.summary.items_count', 1)
        ->assertJsonPath('data.summary.total_quantity', 2)
        ->assertJsonPath('data.summary.subtotal', 700000)
        ->assertJsonPath('data.summary.discount_total', 100000)
        ->assertJsonPath('data.summary.total', 600000);

    $this->assertDatabaseHas('cart_items', [
        'cart_id' => $customer->cart()->firstOrFail()->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    // Menambahkan item ke Cart tidak boleh mereservasi stok.
    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
        'stock_on_hand' => 10,
        'stock_reserved' => 0,
    ]);
});

it('allows customers to update cart item quantity', function () {
    $customer = createCartTestCustomer();
    $variant = createCartTestVariant();

    $this->actingAs($customer, 'sanctum');

    $this->postJson(CUSTOMER_CART_ENDPOINT.'/items', [
        'variant_uuid' => $variant->uuid,
        'quantity' => 1,
    ])->assertCreated();

    $this->patchJson(
        CUSTOMER_CART_ENDPOINT.'/items/'.$variant->uuid,
        ['quantity' => 3]
    )
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 3)
        ->assertJsonPath('data.items.0.line_subtotal', 1050000)
        ->assertJsonPath('data.items.0.line_discount', 150000)
        ->assertJsonPath('data.items.0.line_total', 900000)
        ->assertJsonPath('data.summary.total_quantity', 3)
        ->assertJsonPath('data.summary.total', 900000);

    $this->assertDatabaseHas('cart_items', [
        'cart_id' => $customer->cart()->firstOrFail()->id,
        'product_variant_id' => $variant->id,
        'quantity' => 3,
    ]);
});

it('allows customers to remove an item from their cart', function () {
    $customer = createCartTestCustomer();
    $variant = createCartTestVariant();

    $this->actingAs($customer, 'sanctum');

    $this->postJson(CUSTOMER_CART_ENDPOINT.'/items', [
        'variant_uuid' => $variant->uuid,
        'quantity' => 2,
    ])->assertCreated();

    $this->deleteJson(
        CUSTOMER_CART_ENDPOINT.'/items/'.$variant->uuid
    )
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.summary.items_count', 0)
        ->assertJsonPath('data.summary.total_quantity', 0)
        ->assertJsonPath('data.summary.total', 0);

    $this->assertDatabaseMissing('cart_items', [
        'cart_id' => $customer->cart()->firstOrFail()->id,
        'product_variant_id' => $variant->id,
    ]);
});

it('rejects cart quantities exceeding available stock', function () {
    $customer = createCartTestCustomer();

    $variant = createCartTestVariant([
        'stock_on_hand' => 2,
        'stock_reserved' => 1,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson(CUSTOMER_CART_ENDPOINT.'/items', [
            'variant_uuid' => $variant->uuid,
            'quantity' => 2,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quantity');

    $this->assertDatabaseMissing('cart_items', [
        'product_variant_id' => $variant->id,
    ]);

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
        'stock_on_hand' => 2,
        'stock_reserved' => 1,
    ]);
});

it('validates cart item input', function () {
    $customer = createCartTestCustomer();

    $this->actingAs($customer, 'sanctum');

    $this->postJson(CUSTOMER_CART_ENDPOINT.'/items', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'variant_uuid',
            'quantity',
        ]);

    $this->postJson(CUSTOMER_CART_ENDPOINT.'/items', [
        'variant_uuid' => Str::uuid()->toString(),
        'quantity' => 0,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'variant_uuid',
            'quantity',
        ]);
});

it('rejects adding a cart item when its product configuration is inactive', function (
    string $inactiveRelation
) {
    $customer = createCartTestCustomer();
    $variant = createCartTestVariant();

    match ($inactiveRelation) {
        'variant' => $variant->update(['is_active' => false]),
        'product' => $variant->product->update(['is_active' => false]),
        'size' => $variant->size->update(['is_active' => false]),
        'color' => $variant->color->update(['is_active' => false]),
    };

    $this->actingAs($customer, 'sanctum')
        ->postJson(CUSTOMER_CART_ENDPOINT.'/items', [
            'variant_uuid' => $variant->uuid,
            'quantity' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant_uuid');

    $this->assertDatabaseMissing('cart_items', [
        'product_variant_id' => $variant->id,
    ]);
})->with([
    'variant' => 'variant',
    'product' => 'product',
    'size' => 'size',
    'color' => 'color',
]);

it('rejects updating cart quantity when available stock has decreased', function () {
    $customer = createCartTestCustomer();
    $variant = createCartTestVariant([
        'stock_on_hand' => 3,
        'stock_reserved' => 0,
    ]);

    $this->actingAs($customer, 'sanctum');

    $this->postJson(CUSTOMER_CART_ENDPOINT.'/items', [
        'variant_uuid' => $variant->uuid,
        'quantity' => 1,
    ])->assertCreated();

    // Simulasikan stok yang berkurang karena transaksi pelanggan lain.
    $variant->update([
        'stock_on_hand' => 2,
        'stock_reserved' => 1,
    ]);

    $this->patchJson(
        CUSTOMER_CART_ENDPOINT.'/items/'.$variant->uuid,
        ['quantity' => 2]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quantity');

    $this->assertDatabaseHas('cart_items', [
        'cart_id' => $customer->cart()->firstOrFail()->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
    ]);
});
