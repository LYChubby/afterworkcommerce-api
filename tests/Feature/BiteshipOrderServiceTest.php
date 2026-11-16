<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingQuote;
use App\Models\Size;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BiteshipOrderService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'biteship.api_key' => 'biteship_test_fake_key',
        'biteship.base_url' => 'https://api.biteship.com/v1',
        'biteship.timeout' => 20,
    ]);

    $this->user = User::factory()->create();

    $this->warehouse = Warehouse::create([
        'name' => 'ATELIER Test Warehouse',
        'code' => 'ATL-SHIPMENT-TEST',
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

    $this->category = Category::create([
        'name' => 'Test Category',
        'slug' => 'test-category-shipment',
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'category_id' => $this->category->id,
        'name' => 'Test Shirt',
        'slug' => 'test-shirt-shipment',
        'description' => 'Test product',
        'price' => 350000,
        'discount_price' => 299000,
        'weight_grams' => 500,
        'length_cm' => 30,
        'width_cm' => 25,
        'height_cm' => 5,
        'is_active' => true,
    ]);

    $this->size = Size::create([
        'name' => 'Test M',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->color = Color::create([
        'name' => 'Test Black',
        'hex_code' => '#000000',
        'is_active' => true,
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'size_id' => $this->size->id,
        'color_id' => $this->color->id,
        'sku' => 'TEST-SHIPMENT-001',
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
        'notes' => 'Rumah warna putih',
    ];
});

/**
 * Membuat order dan ShippingQuote untuk kebutuhan test.
 */
function createBiteshipTestOrder($test): Order
{
    $items = [
        [
            'product_variant_id' => $test->variant->id,
            'quantity' => 2,
        ],
    ];

    $quote = ShippingQuote::create([
        'quote_group_uuid' => (string) Str::uuid(),
        'user_id' => $test->user->id,
        'warehouse_id' => $test->warehouse->id,

        'origin_snapshot' => [
            'postal_code' => $test->warehouse->postal_code,
        ],

        'destination_snapshot' => [
            'postal_code' => $test->address['postal_code'],
        ],

        'items_snapshot' => [
            [
                'variant_id' => $test->variant->id,
                'quantity' => 2,
            ],
        ],

        'courier_code' => 'jne',
        'courier_name' => 'JNE',
        'courier_service_code' => 'reg',
        'courier_service_name' => 'Regular',
        'description' => 'Test shipping',
        'duration' => '2 - 3 days',
        'service_type' => 'standard',

        'shipping_fee' => 15000,
        'currency' => 'IDR',
        'expires_at' => now()->addMinutes(15),
    ]);

    $order = app(OrderService::class)->createPendingOrder(
        user: $test->user,
        items: $items,
        shippingAddressSnapshot: $test->address,
        shippingQuoteUuid: $quote->uuid,
    );

    // Simulasikan order yang sudah berhasil dibayar.
    $order->update([
        'payment_status' => 'paid',
        'status' => 'processing',
        'paid_at' => now(),
    ]);

    return $order->fresh();
}

it('creates shipment successfully and saves Biteship response', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'bite-order-test-001',
            'status' => 'confirmed',
            'price' => 15000,
            'courier' => [
                'company' => 'jne',
                'type' => 'reg',
                'tracking_id' => 'bite-tracking-001',
                'waybill_id' => 'JNE123456789',
                'link' => 'https://tracking.example.test/JNE123456789',
            ],
        ], 200),
    ]);

    $shipment = app(BiteshipOrderService::class)
        ->createForOrder($order->id);

    expect($shipment->provider_order_id)
        ->toBe('bite-order-test-001')
        ->and($shipment->tracking_id)
        ->toBe('bite-tracking-001')
        ->and($shipment->waybill_id)
        ->toBe('JNE123456789')
        ->and($shipment->status)
        ->toBe('confirmed')
        ->and($shipment->actual_shipping_fee)
        ->toBe(15000);

    expect($shipment->provider_response)
        ->toBeArray()
        ->and($shipment->order_id)
        ->toBe($order->id);

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'provider_order_id' => 'bite-order-test-001',
        'tracking_id' => 'bite-tracking-001',
    ]);

    Http::assertSent(function ($request) use ($order) {
        $payload = $request->data();

        return $request->url()
            === 'https://api.biteship.com/v1/orders'
            && $request->hasHeader(
                'Authorization',
                'biteship_test_fake_key'
            )
            && $payload['reference_id'] === $order->order_number
            && $payload['courier_company'] === 'jne'
            && $payload['courier_type'] === 'reg'
            && $payload['destination_postal_code'] === 40132
            && $payload['items'][0]['quantity'] === 2
            && $payload['items'][0]['weight'] === 500;
    });
});

it('does not create shipment twice for the same order', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'bite-order-test-002',
            'status' => 'confirmed',
            'courier' => [
                'company' => 'jne',
                'type' => 'reg',
                'tracking_id' => 'bite-tracking-002',
                'waybill_id' => 'JNE987654321',
                'link' => 'https://tracking.example.test/JNE987654321',
            ],
        ], 200),
    ]);

    $service = app(BiteshipOrderService::class);

    $firstShipment = $service->createForOrder($order->id);

    $secondShipment = $service->createForOrder($order->id);

    expect($secondShipment->id)->toBe($firstShipment->id);

    $this->assertDatabaseCount('shipments', 1);

    Http::assertSentCount(1);
});

it('rejects shipment creation when order is not paid', function () {
    $order = createBiteshipTestOrder($this);

    $order->update([
        'payment_status' => 'pending',
        'status' => 'pending',
    ]);

    Http::fake();

    expect(
        fn() => app(BiteshipOrderService::class)
            ->createForOrder($order->id)
    )->toThrow(RuntimeException::class);

    Http::assertNothingSent();

    $this->assertDatabaseCount('shipments', 0);
});

it('marks shipment as creation failed when Biteship API fails', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => false,
            'message' => 'Temporary Biteship API error',
        ], 500),
    ]);

    expect(
        fn() => app(BiteshipOrderService::class)
            ->createForOrder($order->id)
    )->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'status' => 'creation_failed',
    ]);

    Http::assertSentCount(1);
});

it('rejects shipment when courier service information is incomplete', function () {
    $order = createBiteshipTestOrder($this);

    $snapshot = $order->shipping_courier_snapshot;

    $snapshot['courier_service_code'] = null;

    $order->update([
        'shipping_courier_snapshot' => $snapshot,
    ]);

    Http::fake();

    expect(
        fn() => app(BiteshipOrderService::class)
            ->createForOrder($order->id)
    )->toThrow(RuntimeException::class);

    Http::assertNothingSent();

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'status' => 'creation_failed',
    ]);
});
