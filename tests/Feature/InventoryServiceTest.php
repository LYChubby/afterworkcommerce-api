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
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

/**
 * Membuat fixture lengkap untuk pengujian InventoryService.
 */
function createInventoryFixture(array $overrides = []): array
{
    $suffix = Str::lower(Str::random(8));

    $user = User::factory()->create();

    $warehouse = Warehouse::create([
        'name' => 'AFTERWORK Test Warehouse',
        'code' => 'AW-WH-'.strtoupper($suffix),
        'recipient_name' => 'AFTERWORK',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test No. 1',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'district' => 'Coblong',
        'postal_code' => '40132',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $category = Category::create([
        'name' => 'Test Category '.$suffix,
        'slug' => 'test-category-'.$suffix,
        'is_active' => true,
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Test Product '.$suffix,
        'slug' => 'test-product-'.$suffix,
        'description' => 'Test product',
        'price' => 350000,
        'discount_price' => 299000,
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

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'TEST-'.strtoupper($suffix),
        'stock_on_hand' => $overrides['stock_on_hand'] ?? 100,
        'stock_reserved' => $overrides['stock_reserved'] ?? 0,
        'is_active' => $overrides['variant_active'] ?? true,
    ]);

    $order = Order::create([
        'order_number' => 'AW-TEST-'.strtoupper($suffix),
        'user_id' => $user->id,
        'warehouse_id' => $warehouse->id,
        'shipping_address_snapshot' => [
            'recipient_name' => 'Test Customer',
            'phone' => '081234567890',
            'address_line' => 'Jl. Test No. 1',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'postal_code' => '40132',
            'country' => 'Indonesia',
        ],
        'status' => $overrides['order_status'] ?? 'pending',
        'payment_status' => $overrides['payment_status'] ?? 'pending',
        'subtotal' => 598000,
        'item_discount_total' => 0,
        'shipping_cost' => 0,
        'grand_total' => 598000,
        'currency' => 'IDR',
        'payment_deadline_at' => $overrides['payment_deadline_at']
            ?? now()->addMinutes(30),
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'product_name' => $product->name,
        'product_slug' => $product->slug,
        'sku' => $variant->sku,
        'size_name' => $size->name,
        'color_name' => $color->name,
        'color_hex_code' => $color->hex_code,
        'unit_price' => 299000,
        'unit_discount' => 0,
        'quantity' => $overrides['quantity'] ?? 2,
        'line_total' => 598000,
    ]);

    return [
        'user' => $user,
        'warehouse' => $warehouse,
        'product' => $product,
        'variant' => $variant,
        'order' => $order,
        'item' => $item,
        'service' => app(InventoryService::class),
    ];
}

/*
|--------------------------------------------------------------------------
| Reservation Tests
|--------------------------------------------------------------------------
*/

test('inventory service reserves stock for an order item', function () {
    $fixture = createInventoryFixture([
        'stock_on_hand' => 100,
        'stock_reserved' => 15,
        'quantity' => 2,
    ]);

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    $fixture['variant']->refresh();

    expect($reservation)
        ->toBeInstanceOf(InventoryReservation::class)
        ->and($reservation->status)->toBe('active')
        ->and($reservation->quantity)->toBe(2)
        ->and($fixture['variant']->stock_on_hand)->toBe(100)
        ->and($fixture['variant']->stock_reserved)->toBe(17)
        ->and($fixture['variant']->available_stock)->toBe(83);

    expect(
        InventoryMovement::where(
            'product_variant_id',
            $fixture['variant']->id
        )->count()
    )->toBe(1);
});

test('inventory service returns existing active reservation on retry', function () {
    $fixture = createInventoryFixture();

    $firstReservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    $secondReservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    $fixture['variant']->refresh();

    expect($secondReservation->id)->toBe($firstReservation->id)
        ->and($fixture['variant']->stock_reserved)->toBe(2);

    expect(
        InventoryMovement::where(
            'product_variant_id',
            $fixture['variant']->id
        )->count()
    )->toBe(1);
});

test('inventory service rejects insufficient stock', function () {
    $fixture = createInventoryFixture([
        'stock_on_hand' => 1,
        'stock_reserved' => 0,
        'quantity' => 2,
    ]);

    expect(
        fn () => $fixture['service']->reserveForOrderItem(
            $fixture['item']->id
        )
    )->toThrow(RuntimeException::class, 'Stok produk tidak mencukupi.');

    $fixture['variant']->refresh();

    expect($fixture['variant']->stock_reserved)->toBe(0);

    expect(
        InventoryMovement::where(
            'product_variant_id',
            $fixture['variant']->id
        )->count()
    )->toBe(0);
});

test('inventory service rejects inactive product variant', function () {
    $fixture = createInventoryFixture([
        'variant_active' => false,
    ]);

    expect(
        fn () => $fixture['service']->reserveForOrderItem(
            $fixture['item']->id
        )
    )->toThrow(RuntimeException::class, 'Varian produk tidak aktif.');

    $fixture['variant']->refresh();

    expect($fixture['variant']->stock_reserved)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Confirmation Tests
|--------------------------------------------------------------------------
*/

test('inventory service confirms an active reservation', function () {
    $fixture = createInventoryFixture();

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    // Simulasikan pembayaran yang sudah diverifikasi.
    $fixture['order']->payment_status = 'paid';
    $fixture['order']->paid_at = now();
    $fixture['order']->save();

    $confirmed = $fixture['service']->confirmReservation(
        $reservation->id
    );

    expect($confirmed->status)->toBe('confirmed')
        ->and($confirmed->confirmed_at)->not->toBeNull();

    $fixture['variant']->refresh();

    expect($fixture['variant']->stock_on_hand)->toBe(98)
        ->and($fixture['variant']->stock_reserved)->toBe(0)
        ->and($fixture['variant']->available_stock)->toBe(98);

    expect(
        InventoryMovement::where('product_variant_id', $fixture['variant']->id)
            ->where('movement_type', 'stock_out')
            ->count()
    )->toBe(1);
});

test('inventory service does not confirm the same reservation twice', function () {
    $fixture = createInventoryFixture();

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    $fixture['order']->payment_status = 'paid';
    $fixture['order']->paid_at = now();
    $fixture['order']->save();

    $firstConfirmation = $fixture['service']->confirmReservation(
        $reservation->id
    );

    $secondConfirmation = $fixture['service']->confirmReservation(
        $reservation->id
    );

    $fixture['variant']->refresh();

    expect($secondConfirmation->id)->toBe($firstConfirmation->id)
        ->and($fixture['variant']->stock_on_hand)->toBe(98)
        ->and($fixture['variant']->stock_reserved)->toBe(0);

    expect(
        InventoryMovement::where('product_variant_id', $fixture['variant']->id)
            ->where('movement_type', 'stock_out')
            ->count()
    )->toBe(1);
});

test('inventory service rejects confirmation when payment is still pending', function () {
    $fixture = createInventoryFixture();

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    expect(
        fn () => $fixture['service']->confirmReservation(
            $reservation->id
        )
    )->toThrow(
        RuntimeException::class,
        'Pembayaran pesanan belum diverifikasi.'
    );

    $fixture['variant']->refresh();

    expect($fixture['variant']->stock_on_hand)->toBe(100)
        ->and($fixture['variant']->stock_reserved)->toBe(2);
});

test('inventory service rejects payment made after reservation deadline', function () {
    $fixture = createInventoryFixture();

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    $fixture['order']->payment_status = 'paid';
    $fixture['order']->paid_at = $reservation->expires_at->copy()->addSecond();
    $fixture['order']->save();

    expect(
        fn () => $fixture['service']->confirmReservation(
            $reservation->id
        )
    )->toThrow(
        RuntimeException::class,
        'Pembayaran terjadi setelah batas waktu reservasi.'
    );

    $fixture['variant']->refresh();

    expect($fixture['variant']->stock_on_hand)->toBe(100)
        ->and($fixture['variant']->stock_reserved)->toBe(2);

    expect(
        InventoryMovement::where('product_variant_id', $fixture['variant']->id)
            ->where('movement_type', 'stock_out')
            ->count()
    )->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Release Tests
|--------------------------------------------------------------------------
*/

test('inventory service releases an active reservation', function () {
    $fixture = createInventoryFixture();

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    // Simulasikan pesanan yang sudah dibatalkan.
    $fixture['order']->status = 'cancelled';
    $fixture['order']->save();

    $released = $fixture['service']->releaseReservation(
        $reservation->id
    );

    expect($released->status)->toBe('released')
        ->and($released->released_at)->not->toBeNull();

    $fixture['variant']->refresh();

    expect($fixture['variant']->stock_on_hand)->toBe(100)
        ->and($fixture['variant']->stock_reserved)->toBe(0)
        ->and($fixture['variant']->available_stock)->toBe(100);

    expect(
        InventoryMovement::where('product_variant_id', $fixture['variant']->id)
            ->where('movement_type', 'reservation_release')
            ->count()
    )->toBe(1);
});

test('inventory service does not release the same reservation twice', function () {
    $fixture = createInventoryFixture();

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    $fixture['order']->status = 'cancelled';
    $fixture['order']->save();

    $firstRelease = $fixture['service']->releaseReservation(
        $reservation->id
    );

    $secondRelease = $fixture['service']->releaseReservation(
        $reservation->id
    );

    $fixture['variant']->refresh();

    expect($secondRelease->id)->toBe($firstRelease->id)
        ->and($fixture['variant']->stock_reserved)->toBe(0)
        ->and($fixture['variant']->stock_on_hand)->toBe(100);

    expect(
        InventoryMovement::where('product_variant_id', $fixture['variant']->id)
            ->where('movement_type', 'reservation_release')
            ->count()
    )->toBe(1);
});

test('inventory service rejects releasing reservation before order cancellation', function () {
    $fixture = createInventoryFixture();

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    expect(
        fn () => $fixture['service']->releaseReservation(
            $reservation->id
        )
    )->toThrow(
        RuntimeException::class,
        'Pesanan harus dibatalkan sebelum reservasi dilepaskan.'
    );

    $fixture['variant']->refresh();

    expect($fixture['variant']->stock_reserved)->toBe(2);
});

test('inventory service rejects expiring reservation before deadline', function () {
    $fixture = createInventoryFixture();

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    expect(
        fn () => $fixture['service']->releaseReservation(
            $reservation->id,
            'expired'
        )
    )->toThrow(
        RuntimeException::class,
        'Batas waktu reservasi belum berakhir.'
    );

    $fixture['variant']->refresh();

    expect($fixture['variant']->stock_reserved)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Terminal Reservation Tests
|--------------------------------------------------------------------------
*/

test('inventory service rejects reusing a released reservation', function () {
    $fixture = createInventoryFixture();

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    // Simulasikan reservasi yang sudah mencapai status terminal.
    $reservation->status = 'released';
    $reservation->save();

    expect(
        fn () => $fixture['service']->reserveForOrderItem(
            $fixture['item']->id
        )
    )->toThrow(
        RuntimeException::class,
        'Item pesanan sudah memiliki reservasi dengan status akhir.'
    );
});

test('inventory service rejects reusing an expired reservation', function () {
    $fixture = createInventoryFixture();

    $reservation = $fixture['service']->reserveForOrderItem(
        $fixture['item']->id
    );

    // Simulasikan reservasi yang sudah mencapai status terminal.
    $reservation->status = 'expired';
    $reservation->save();

    expect(
        fn () => $fixture['service']->reserveForOrderItem(
            $fixture['item']->id
        )
    )->toThrow(
        RuntimeException::class,
        'Item pesanan sudah memiliki reservasi dengan status akhir.'
    );
});
