
<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'password' => 'password',
    ]);

    $this->warehouse = Warehouse::create([
        'name' => 'ATELIER Test Warehouse',
        'code' => 'ATL-ORDER-TEST',
        'recipient_name' => 'ATELIER',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test No. 1',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'postal_code' => '40111',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $this->category = Category::create([
        'name' => 'Test Category',
        'slug' => 'test-category-order',
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'category_id' => $this->category->id,
        'name' => 'Essential Oversized Shirt',
        'slug' => 'essential-oversized-shirt-order-test',
        'description' => 'Test product',
        'price' => 350000,
        'discount_price' => 299000,
        'is_active' => true,
    ]);

    $this->size = Size::create([
        'name' => 'M',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->color = Color::create([
        'name' => 'Black',
        'hex_code' => '#000000',
        'is_active' => true,
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'size_id' => $this->size->id,
        'color_id' => $this->color->id,
        'sku' => 'ATL-ORDER-TEST-M-BLK',
        'stock_on_hand' => 100,
        'stock_reserved' => 0,
        'is_active' => true,
    ]);

    $this->shippingAddress = [
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

test('order service creates an order and reserves stock', function () {
    $order = app(OrderService::class)->createPendingOrder(
        user: $this->user,
        items: [
            [
                'product_variant_id' => $this->variant->id,
                'quantity' => 2,

                // Nilai ini sengaja dimanipulasi.
                // Service tidak boleh menggunakannya.
                'price' => 1,
            ],
        ],
        shippingAddressSnapshot: $this->shippingAddress,
        shippingCost: 15000,
    );

    expect($order->status)->toBe('pending')
        ->and($order->payment_status)->toBe('pending')
        ->and((float) $order->subtotal)->toBe(700000.0)
        ->and((float) $order->item_discount_total)->toBe(102000.0)
        ->and((float) $order->shipping_cost)->toBe(15000.0)
        ->and((float) $order->grand_total)->toBe(613000.0)
        ->and($order->payment_deadline_at)->not->toBeNull();

    expect($order->items)->toHaveCount(1)
        ->and($order->inventoryReservations)->toHaveCount(1);

    $orderItem = $order->items->first();

    expect($orderItem->product_name)
        ->toBe('Essential Oversized Shirt')
        ->and($orderItem->sku)
        ->toBe('ATL-ORDER-TEST-M-BLK')
        ->and((float) $orderItem->unit_price)
        ->toBe(350000.0)
        ->and((float) $orderItem->unit_discount)
        ->toBe(51000.0)
        ->and((float) $orderItem->line_total)
        ->toBe(598000.0);

    $this->variant->refresh();

    expect($this->variant->stock_on_hand)->toBe(100)
        ->and($this->variant->stock_reserved)->toBe(2)
        ->and($this->variant->available_stock)->toBe(98);

    $this->assertDatabaseHas('inventory_reservations', [
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'quantity' => 2,
        'status' => 'active',
    ]);

    $this->assertDatabaseHas('inventory_movements', [
        'product_variant_id' => $this->variant->id,
        'movement_type' => 'reservation',
        'quantity' => 2,
    ]);
});

test('order service rolls back everything when stock is insufficient', function () {
    $this->variant->update([
        'stock_on_hand' => 1,
        'stock_reserved' => 0,
    ]);

    expect(fn() => app(OrderService::class)->createPendingOrder(
        user: $this->user,
        items: [
            [
                'product_variant_id' => $this->variant->id,
                'quantity' => 2,
            ],
        ],
        shippingAddressSnapshot: $this->shippingAddress,
        shippingCost: 15000,
    ))->toThrow(RuntimeException::class);

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0)
        ->and(InventoryReservation::count())->toBe(0)
        ->and(InventoryMovement::count())->toBe(0);

    $this->variant->refresh();

    expect($this->variant->stock_on_hand)->toBe(1)
        ->and($this->variant->stock_reserved)->toBe(0);
});

test('order service combines duplicate variants into one order item', function () {
    $order = app(OrderService::class)->createPendingOrder(
        user: $this->user,
        items: [
            [
                'product_variant_id' => $this->variant->id,
                'quantity' => 1,
            ],
            [
                'product_variant_id' => $this->variant->id,
                'quantity' => 2,
            ],
        ],
        shippingAddressSnapshot: $this->shippingAddress,
        shippingCost: 0,
    );

    expect($order->items)->toHaveCount(1)
        ->and($order->items->first()->quantity)->toBe(3)
        ->and($order->inventoryReservations)->toHaveCount(1)
        ->and($order->inventoryReservations->first()->quantity)->toBe(3);

    $this->variant->refresh();

    expect($this->variant->stock_reserved)->toBe(3)
        ->and($this->variant->available_stock)->toBe(97);
});
