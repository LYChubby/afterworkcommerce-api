<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderCancellationService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'password' => 'password',
    ]);

    $this->otherUser = User::factory()->create([
        'password' => 'password',
    ]);

    $this->warehouse = Warehouse::create([
        'name' => 'ATELIER Test Warehouse',
        'code' => 'ATL-CANCEL-TEST',
        'recipient_name' => 'ATELIER',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test No. 1',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'postal_code' => '40111',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $category = Category::create([
        'name' => 'Cancellation Test',
        'slug' => 'cancellation-test',
        'is_active' => true,
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Cancellation Test Product',
        'slug' => 'cancellation-test-product',
        'description' => 'Test product',
        'price' => 350000,
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
        'sku' => 'ATL-CANCEL-TEST-M-BLK',
        'stock_on_hand' => 100,
        'stock_reserved' => 0,
        'is_active' => true,
    ]);

    $this->address = [
        'recipient_name' => 'Test Customer',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test No. 2',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'district' => 'Coblong',
        'postal_code' => '40132',
        'country' => 'Indonesia',
    ];
});

function createTestOrder($user, $variant, $address)
{
    return app(OrderService::class)->createPendingOrder(
        user: $user,
        items: [
            [
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ],
        ],
        shippingAddressSnapshot: $address,
        shippingCost: 0,
    );
}

test('customer can cancel an unpaid order and release stock', function () {
    $order = createTestOrder(
        $this->user,
        $this->variant,
        $this->address
    );

    $cancelled = app(OrderCancellationService::class)
        ->cancelPendingOrder($order->id, $this->user->id);

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancelled_at)->not->toBeNull();

    $this->variant->refresh();

    expect($this->variant->stock_on_hand)->toBe(100)
        ->and($this->variant->stock_reserved)->toBe(0)
        ->and($this->variant->available_stock)->toBe(100);

    $this->assertDatabaseHas('inventory_reservations', [
        'order_id' => $order->id,
        'status' => 'released',
    ]);
});

test('customer cannot cancel another customers order', function () {
    $order = createTestOrder(
        $this->user,
        $this->variant,
        $this->address
    );

    expect(
        fn() => app(OrderCancellationService::class)
            ->cancelPendingOrder($order->id, $this->otherUser->id)
    )->toThrow(RuntimeException::class);

    expect($order->fresh()->status)->toBe('pending');

    $this->variant->refresh();

    expect($this->variant->stock_reserved)->toBe(2);
});

test('expired order releases its reserved stock', function () {
    $order = createTestOrder(
        $this->user,
        $this->variant,
        $this->address
    );

    // Simulasikan batas pembayaran yang sudah lewat.
    $expiredAt = now()->subMinute();

    $order->update([
        'payment_deadline_at' => $expiredAt,
    ]);

    $order->inventoryReservations()->update([
        'expires_at' => $expiredAt,
    ]);

    $expiredOrder = app(OrderCancellationService::class)
        ->expirePendingOrder($order->id);

    expect($expiredOrder->status)->toBe('expired');

    $this->variant->refresh();

    expect($this->variant->stock_on_hand)->toBe(100)
        ->and($this->variant->stock_reserved)->toBe(0)
        ->and($this->variant->available_stock)->toBe(100);

    $this->assertDatabaseHas('inventory_reservations', [
        'order_id' => $order->id,
        'status' => 'expired',
    ]);
});

test('order cannot expire before its payment deadline', function () {
    $order = createTestOrder(
        $this->user,
        $this->variant,
        $this->address
    );

    expect(
        fn() => app(OrderCancellationService::class)
            ->expirePendingOrder($order->id)
    )->toThrow(RuntimeException::class);

    expect($order->fresh()->status)->toBe('pending');

    $this->variant->refresh();

    expect($this->variant->stock_reserved)->toBe(2);
});

test('cancelling an order twice does not release stock twice', function () {
    $order = createTestOrder(
        $this->user,
        $this->variant,
        $this->address
    );

    $service = app(OrderCancellationService::class);

    $service->cancelPendingOrder($order->id, $this->user->id);
    $service->cancelPendingOrder($order->id, $this->user->id);

    $this->variant->refresh();

    expect($this->variant->stock_on_hand)->toBe(100)
        ->and($this->variant->stock_reserved)->toBe(0);

    expect(
        InventoryReservation::where('order_id', $order->id)
            ->where('status', 'released')
            ->count()
    )->toBe(1);
});
