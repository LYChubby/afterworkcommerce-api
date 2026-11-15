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
