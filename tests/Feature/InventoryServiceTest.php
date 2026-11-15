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
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('inventory service reserves stock for an order item', function () {
    // 1. Buat pelanggan
    $user = User::create([
        'name' => 'Test Customer',
        'email' => 'customer@example.com',
        'password' => 'password',
    ]);

    // 2. Buat gudang
    $warehouse = Warehouse::create([
        'name' => 'ATELIER Test Warehouse',
        'code' => 'TEST-WH-001',
        'recipient_name' => 'Test Warehouse',
        'phone' => '081234567890',
        'address_line' => 'Test Address',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'district' => 'Coblong',
        'postal_code' => '40132',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    // 3. Buat kategori
    $category = Category::create([
        'name' => 'Test Category',
        'slug' => 'test-category',
        'is_active' => true,
    ]);

    // 4. Buat produk
    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Test Shirt',
        'slug' => 'test-shirt',
        'description' => 'Test product',
        'price' => 350000,
        'discount_price' => 299000,
        'is_active' => true,
    ]);

    // 5. Buat ukuran dan warna
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

    // 6. Buat varian dengan stok 100
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'TEST-M-BLK',
        'stock_on_hand' => 100,
        'stock_reserved' => 15,
        'is_active' => true,
    ]);

    // 7. Buat pesanan
    $order = Order::create([
        'order_number' => 'ATL-TEST-0001',
        'user_id' => $user->id,
        'warehouse_id' => $warehouse->id,
        'shipping_address_snapshot' => [
            'recipient_name' => 'Test Customer',
            'phone' => '081234567890',
            'address_line' => 'Test Address',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'postal_code' => '40132',
            'country' => 'Indonesia',
        ],
        'status' => 'pending',
        'payment_status' => 'pending',
        'subtotal' => 598000,
        'item_discount_total' => 0,
        'shipping_cost' => 0,
        'grand_total' => 598000,
        'currency' => 'IDR',
        'payment_deadline_at' => now()->addMinutes(30),
    ]);

    // 8. Buat item pesanan sebanyak 2 unit
    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'product_name' => 'Test Shirt',
        'product_slug' => 'test-shirt',
        'sku' => 'TEST-M-BLK',
        'size_name' => 'M',
        'color_name' => 'Black',
        'color_hex_code' => '#000000',
        'unit_price' => 299000,
        'unit_discount' => 0,
        'quantity' => 2,
        'line_total' => 598000,
    ]);

    // 9. Jalankan Inventory Service
    $service = app(InventoryService::class);

    $reservation = $service->reserveForOrderItem($item->id);

    // 10. Periksa hasil reservasi
    $variant->refresh();

    expect($reservation)
        ->toBeInstanceOf(InventoryReservation::class)
        ->and($reservation->status)->toBe('active')
        ->and($reservation->quantity)->toBe(2)
        ->and($variant->stock_on_hand)->toBe(100)
        ->and($variant->stock_reserved)->toBe(17)
        ->and($variant->available_stock)->toBe(83);

    // 11. Pastikan riwayat stok tercatat
    expect(
        InventoryMovement::where(
            'product_variant_id',
            $variant->id
        )->count()
    )->toBe(1);
});


test('inventory service confirms an active reservation', function () {
    $user = \App\Models\User::factory()->create([
        'password' => 'password',
    ]);

    $warehouse = \App\Models\Warehouse::create([
        'name' => 'ATELIER Main Warehouse',
        'code' => 'ATL-WH-TEST-001',
        'recipient_name' => 'ATELIER',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test No. 1',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'postal_code' => '40111',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $category = \App\Models\Category::create([
        'name' => 'Test Category',
        'slug' => 'test-category-confirm',
        'is_active' => true,
    ]);

    $product = \App\Models\Product::create([
        'category_id' => $category->id,
        'name' => 'Test Product',
        'slug' => 'test-product-confirm',
        'description' => 'Test product',
        'price' => 350000,
        'is_active' => true,
    ]);

    $size = \App\Models\Size::create([
        'name' => 'M',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $color = \App\Models\Color::create([
        'name' => 'Black',
        'hex_code' => '#000000',
        'is_active' => true,
    ]);

    $variant = \App\Models\ProductVariant::create([
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'TEST-CONFIRM-M-BLK',
        'stock_on_hand' => 100,
        'stock_reserved' => 0,
        'is_active' => true,
    ]);

    $order = \App\Models\Order::create([
        'order_number' => 'ATL-TEST-CONFIRM-001',
        'user_id' => $user->id,
        'warehouse_id' => $warehouse->id,
        'shipping_address_snapshot' => [
            'recipient_name' => 'Test Customer',
            'phone' => '081234567890',
            'address_line' => 'Jl. Test No. 2',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'postal_code' => '40111',
            'country' => 'Indonesia',
        ],
        'status' => 'pending',
        'payment_status' => 'pending',
        'subtotal' => 700000,
        'item_discount_total' => 0,
        'shipping_cost' => 0,
        'grand_total' => 700000,
        'payment_deadline_at' => now()->addMinutes(30),
    ]);

    $item = \App\Models\OrderItem::create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'product_name' => $product->name,
        'product_slug' => $product->slug,
        'sku' => $variant->sku,
        'size_name' => $size->name,
        'color_name' => $color->name,
        'color_hex_code' => $color->hex_code,
        'unit_price' => 350000,
        'unit_discount' => 0,
        'quantity' => 2,
        'line_total' => 700000,
    ]);

    $service = app(\App\Services\InventoryService::class);

    $reservation = $service->reserveForOrderItem($item->id);

    // Simulasikan pembayaran yang sudah diverifikasi.
    $order->payment_status = 'paid';
    $order->paid_at = now();
    $order->save();

    $confirmed = $service->confirmReservation($reservation->id);

    expect($confirmed->status)->toBe('confirmed')
        ->and($confirmed->confirmed_at)->not->toBeNull();

    $variant->refresh();

    expect($variant->stock_on_hand)->toBe(98)
        ->and($variant->stock_reserved)->toBe(0)
        ->and($variant->available_stock)->toBe(98);

    $this->assertDatabaseHas('inventory_movements', [
        'product_variant_id' => $variant->id,
        'movement_type' => 'stock_out',
        'quantity' => 2,
    ]);
});

test('inventory service releases an active reservation', function () {
    $user = \App\Models\User::factory()->create([
        'password' => 'password',
    ]);

    $warehouse = \App\Models\Warehouse::create([
        'name' => 'ATELIER Main Warehouse',
        'code' => 'ATL-WH-TEST-002',
        'recipient_name' => 'ATELIER',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test No. 1',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'postal_code' => '40111',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $category = \App\Models\Category::create([
        'name' => 'Test Category',
        'slug' => 'test-category-release',
        'is_active' => true,
    ]);

    $product = \App\Models\Product::create([
        'category_id' => $category->id,
        'name' => 'Test Product',
        'slug' => 'test-product-release',
        'description' => 'Test product',
        'price' => 350000,
        'is_active' => true,
    ]);

    $size = \App\Models\Size::create([
        'name' => 'M',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $color = \App\Models\Color::create([
        'name' => 'Black',
        'hex_code' => '#000000',
        'is_active' => true,
    ]);

    $variant = \App\Models\ProductVariant::create([
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'TEST-RELEASE-M-BLK',
        'stock_on_hand' => 100,
        'stock_reserved' => 0,
        'is_active' => true,
    ]);

    $order = \App\Models\Order::create([
        'order_number' => 'ATL-TEST-RELEASE-001',
        'user_id' => $user->id,
        'warehouse_id' => $warehouse->id,
        'shipping_address_snapshot' => [
            'recipient_name' => 'Test Customer',
            'phone' => '081234567890',
            'address_line' => 'Jl. Test No. 2',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'postal_code' => '40111',
            'country' => 'Indonesia',
        ],
        'status' => 'pending',
        'payment_status' => 'pending',
        'subtotal' => 700000,
        'item_discount_total' => 0,
        'shipping_cost' => 0,
        'grand_total' => 700000,
        'payment_deadline_at' => now()->addMinutes(30),
    ]);

    $item = \App\Models\OrderItem::create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'product_name' => $product->name,
        'product_slug' => $product->slug,
        'sku' => $variant->sku,
        'size_name' => $size->name,
        'color_name' => $color->name,
        'color_hex_code' => $color->hex_code,
        'unit_price' => 350000,
        'unit_discount' => 0,
        'quantity' => 2,
        'line_total' => 700000,
    ]);

    $service = app(\App\Services\InventoryService::class);

    $reservation = $service->reserveForOrderItem($item->id);

    // Simulasikan pesanan yang sudah dibatalkan.
    $order->status = 'cancelled';
    $order->save();

    $released = $service->releaseReservation($reservation->id);

    expect($released->status)->toBe('released')
        ->and($released->released_at)->not->toBeNull();

    $variant->refresh();

    expect($variant->stock_on_hand)->toBe(100)
        ->and($variant->stock_reserved)->toBe(0)
        ->and($variant->available_stock)->toBe(100);

    $this->assertDatabaseHas('inventory_movements', [
        'product_variant_id' => $variant->id,
        'movement_type' => 'reservation_release',
        'quantity' => 2,
    ]);
});
