<?php

use App\Models\Address;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingQuote;
use App\Models\Size;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customer = User::factory()->create([
        'role' => 'customer',
    ]);

    $this->warehouse = Warehouse::create([
        'name' => 'Checkout Test Warehouse',
        'code' => 'ATL-CHECKOUT-TEST',
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
        'name' => 'Checkout Category',
        'slug' => 'checkout-'.Str::lower(Str::random(8)),
        'is_active' => true,
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Checkout Test Product',
        'slug' => 'checkout-product-'.Str::lower(Str::random(8)),
        'description' => 'Product for checkout tests.',
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
        'sku' => 'ATL-CHECKOUT-TEST',
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

function createCheckoutQuote(
    User $user,
    Warehouse $warehouse,
    ProductVariant $variant,
    int $quantity,
    int $fee = 15000
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
        'description' => 'Checkout test shipping',
        'duration' => '2 - 3 days',
        'service_type' => 'standard',
        'shipping_fee' => $fee,
        'currency' => 'IDR',
        'expires_at' => now()->addMinutes(15),
    ]);
}

it('allows direct checkout without modifying the cart', function () {
    $cart = $this->customer->cart()->create();

    $cartItem = CartItem::create([
        'cart_id' => $cart->id,
        'product_variant_id' => $this->variant->id,
        'quantity' => 3,
    ]);

    $quote = createCheckoutQuote(
        $this->customer,
        $this->warehouse,
        $this->variant,
        2
    );

    $order = app(CheckoutService::class)->checkout(
        user: $this->customer,
        source: 'direct',
        items: [
            [
                'variant_uuid' => $this->variant->uuid,
                'quantity' => 2,
            ],
        ],
        addressUuid: $this->address->uuid,
        shippingQuoteUuid: $quote->uuid,
    );

    expect($order->status)->toBe('pending')
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->quantity)->toBe(2);

    $this->assertDatabaseHas('cart_items', [
        'id' => $cartItem->id,
        'quantity' => 3,
    ]);

    $this->assertDatabaseHas('product_variants', [
        'id' => $this->variant->id,
        'stock_reserved' => 2,
    ]);
});

it('removes only selected cart items after successful checkout', function () {
    $cart = $this->customer->cart()->create();

    $selectedItem = CartItem::create([
        'cart_id' => $cart->id,
        'product_variant_id' => $this->variant->id,
        'quantity' => 2,
    ]);

    $otherCategory = Category::create([
        'name' => 'Other Category',
        'slug' => 'other-'.Str::lower(Str::random(8)),
        'is_active' => true,
    ]);

    $otherProduct = Product::create([
        'category_id' => $otherCategory->id,
        'name' => 'Other Product',
        'slug' => 'other-product-'.Str::lower(Str::random(8)),
        'description' => 'Another product.',
        'price' => 200000,
        'discount_price' => null,
        'is_active' => true,
    ]);

    $otherSize = Size::create([
        'name' => 'L',
        'sort_order' => 2,
        'is_active' => true,
    ]);

    $otherColor = Color::create([
        'name' => 'White',
        'hex_code' => '#FFFFFF',
        'is_active' => true,
    ]);

    $otherVariant = ProductVariant::create([
        'product_id' => $otherProduct->id,
        'size_id' => $otherSize->id,
        'color_id' => $otherColor->id,
        'sku' => 'ATL-CHECKOUT-OTHER',
        'stock_on_hand' => 10,
        'stock_reserved' => 0,
        'is_active' => true,
    ]);

    $remainingItem = CartItem::create([
        'cart_id' => $cart->id,
        'product_variant_id' => $otherVariant->id,
        'quantity' => 1,
    ]);

    $quote = createCheckoutQuote(
        $this->customer,
        $this->warehouse,
        $this->variant,
        2
    );

    $order = app(CheckoutService::class)->checkout(
        user: $this->customer,
        source: 'cart',
        items: [
            [
                'variant_uuid' => $this->variant->uuid,
            ],
        ],
        addressUuid: $this->address->uuid,
        shippingQuoteUuid: $quote->uuid,
    );

    expect($order->items)->toHaveCount(1)
        ->and($order->items->first()->quantity)->toBe(2);

    $this->assertDatabaseMissing('cart_items', [
        'id' => $selectedItem->id,
    ]);

    $this->assertDatabaseHas('cart_items', [
        'id' => $remainingItem->id,
        'cart_id' => $cart->id,
        'product_variant_id' => $otherVariant->id,
        'quantity' => 1,
    ]);
});

it('rolls back order and cart changes when stock is insufficient', function () {
    $this->variant->update([
        'stock_on_hand' => 1,
        'stock_reserved' => 0,
    ]);

    $cart = $this->customer->cart()->create();

    $cartItem = CartItem::create([
        'cart_id' => $cart->id,
        'product_variant_id' => $this->variant->id,
        'quantity' => 2,
    ]);

    $quote = createCheckoutQuote(
        $this->customer,
        $this->warehouse,
        $this->variant,
        2
    );

    expect(fn () => app(CheckoutService::class)->checkout(
        user: $this->customer,
        source: 'cart',
        items: [
            [
                'variant_uuid' => $this->variant->uuid,
            ],
        ],
        addressUuid: $this->address->uuid,
        shippingQuoteUuid: $quote->uuid,
    ))->toThrow(RuntimeException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);

    $this->assertDatabaseHas('cart_items', [
        'id' => $cartItem->id,
        'quantity' => 2,
    ]);

    $this->assertDatabaseHas('shipping_quotes', [
        'id' => $quote->id,
        'order_id' => null,
        'used_at' => null,
    ]);

    $this->assertDatabaseHas('product_variants', [
        'id' => $this->variant->id,
        'stock_on_hand' => 1,
        'stock_reserved' => 0,
    ]);
});

it('rejects checkout using another customers address', function () {
    $otherCustomer = User::factory()->create([
        'role' => 'customer',
    ]);

    $otherAddress = Address::create([
        'user_id' => $otherCustomer->id,
        'label' => 'Rumah',
        'recipient_name' => 'Other Customer',
        'phone' => '081234567890',
        'address_line' => 'Jl. Other No. 1',
        'province' => 'DI Yogyakarta',
        'city' => 'Yogyakarta',
        'district' => 'Gondokusuman',
        'subdistrict' => 'Baciro',
        'postal_code' => '55225',
        'country' => 'Indonesia',
        'notes' => null,
        'is_default' => true,
    ]);

    $quote = createCheckoutQuote(
        $this->customer,
        $this->warehouse,
        $this->variant,
        1
    );

    expect(fn () => app(CheckoutService::class)->checkout(
        user: $this->customer,
        source: 'direct',
        items: [
            [
                'variant_uuid' => $this->variant->uuid,
                'quantity' => 1,
            ],
        ],
        addressUuid: $otherAddress->uuid,
        shippingQuoteUuid: $quote->uuid,
    ))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0);
});
