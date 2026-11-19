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

it('recovers existing Biteship order when reference id is duplicated', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake(function ($request) {
        if ($request->method() === 'POST') {
            return Http::response([
                'success' => false,
                'code' => 40002060,
                'message' => 'Reference ID already exists.',
                'details' => [
                    'order_id' => 'bite-order-existing-001',
                ],
            ], 400);
        }

        if ($request->method() === 'GET') {
            return Http::response([
                'success' => true,
                'id' => 'bite-order-existing-001',
                'status' => 'confirmed',
                'price' => 15000,
                'courier' => [
                    'company' => 'jne',
                    'type' => 'reg',
                    'tracking_id' => 'bite-tracking-existing-001',
                    'waybill_id' => 'JNE111222333',
                    'link' => 'https://tracking.example.test/JNE111222333',
                ],
            ], 200);
        }

        return Http::response([], 404);
    });

    $shipment = app(BiteshipOrderService::class)
        ->createForOrder($order->id);

    expect($shipment->provider_order_id)
        ->toBe('bite-order-existing-001')
        ->and($shipment->tracking_id)
        ->toBe('bite-tracking-existing-001')
        ->and($shipment->status)
        ->toBe('confirmed');

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'provider_order_id' => 'bite-order-existing-001',
        'status' => 'confirmed',
    ]);

    Http::assertSentCount(2);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.biteship.com/v1/orders';
    });

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.biteship.com/v1/orders/bite-order-existing-001';
    });
});

it('marks shipment as creation failed when Biteship response has no order id', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'status' => 'confirmed',
            'price' => 15000,
        ], 200),
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

it('marks shipment as creation failed when duplicate reference has no order id', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => false,
            'code' => 40002060,
            'message' => 'Reference ID already exists.',
            'details' => [],
        ], 400),
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


it('marks shipment as creation failed when recovering existing Biteship order fails', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake(function ($request) {
        if ($request->method() === 'POST') {
            return Http::response([
                'success' => false,
                'code' => 40002060,
                'details' => [
                    'order_id' => 'bite-order-recovery-failed',
                ],
            ], 400);
        }

        if ($request->method() === 'GET') {
            return Http::response([
                'success' => false,
                'message' => 'Temporary server error.',
            ], 500);
        }

        return Http::response([], 404);
    });

    expect(
        fn() => app(BiteshipOrderService::class)
            ->createForOrder($order->id)
    )->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'status' => 'creation_failed',
        'provider_order_id' => null,
    ]);

    Http::assertSentCount(2);
});

it('marks shipment as creation failed when recovered Biteship order response is invalid', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake(function ($request) {
        if ($request->method() === 'POST') {
            return Http::response([
                'success' => false,
                'code' => 40002060,
                'details' => [
                    'order_id' => 'bite-order-invalid-response',
                ],
            ], 400);
        }

        if ($request->method() === 'GET') {
            return Http::response([
                'success' => true,
                'status' => 'confirmed',
                'price' => 15000,
            ], 200);
        }

        return Http::response([], 404);
    });

    expect(
        fn() => app(BiteshipOrderService::class)
            ->createForOrder($order->id)
    )->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'status' => 'creation_failed',
        'provider_order_id' => null,
    ]);

    Http::assertSentCount(2);
});

it('rejects Biteship response with an invalid shipment status', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'bite-order-invalid-status',
            'status' => '',
            'courier' => [
                'company' => 'jne',
                'type' => 'reg',
            ],
        ], 200),
    ]);

    expect(
        fn() => app(BiteshipOrderService::class)
            ->createForOrder($order->id)
    )->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'status' => 'creation_failed',
        'provider_order_id' => null,
    ]);
});

it('rejects Biteship response with an invalid tracking URL', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'bite-order-invalid-tracking-url',
            'status' => 'confirmed',
            'courier' => [
                'company' => 'jne',
                'type' => 'reg',
                'link' => 'not-a-valid-url',
            ],
        ], 200),
    ]);

    expect(
        fn() => app(BiteshipOrderService::class)
            ->createForOrder($order->id)
    )->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'status' => 'creation_failed',
        'provider_order_id' => null,
    ]);
});

it('rejects Biteship response with invalid courier field types', function () {
    $invalidFields = [
        'company' => 123,
        'type' => ['reg'],
        'tracking_id' => false,
        'waybill_id' => '',
    ];

    foreach ($invalidFields as $field => $invalidValue) {
        $order = createBiteshipTestOrder($this);

        Http::fake([
            'api.biteship.com/v1/orders' => Http::response([
                'success' => true,
                'id' => 'bite-order-invalid-' . $field,
                'status' => 'confirmed',
                'courier' => [
                    'company' => 'jne',
                    'type' => 'reg',
                    'tracking_id' => 'bite-tracking-valid',
                    'waybill_id' => 'JNE123456789',
                    $field => $invalidValue,
                ],
            ], 200),
        ]);

        expect(
            fn() => app(BiteshipOrderService::class)
                ->createForOrder($order->id)
        )->toThrow(RuntimeException::class);

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'status' => 'creation_failed',
            'provider_order_id' => null,
        ]);
    }
});

it('rejects Biteship response when shipping fee exceeds database integer limit', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'bite-order-price-overflow',
            'status' => 'confirmed',
            'price' => '9223372036854775808',
            'courier' => [
                'company' => 'jne',
                'type' => 'reg',
            ],
        ], 200),
    ]);

    expect(
        fn() => app(BiteshipOrderService::class)
            ->createForOrder($order->id)
    )->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'status' => 'creation_failed',
        'provider_order_id' => null,
        'actual_shipping_fee' => null,
    ]);

    Http::assertSentCount(1);
});

it('accepts maximum valid shipping fee supported by database integer', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'bite-order-max-price',
            'status' => 'confirmed',
            'price' => (string) PHP_INT_MAX,
            'courier' => [
                'company' => 'jne',
                'type' => 'reg',
            ],
        ], 200),
    ]);

    $shipment = app(BiteshipOrderService::class)
        ->createForOrder($order->id);

    expect($shipment->actual_shipping_fee)
        ->toBe(PHP_INT_MAX);

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'provider_order_id' => 'bite-order-max-price',
        'actual_shipping_fee' => PHP_INT_MAX,
        'status' => 'confirmed',
    ]);

    Http::assertSentCount(1);
});

it('rejects Biteship response with an unknown shipment status', function () {
    $order = createBiteshipTestOrder($this);

    Http::fake([
        'api.biteship.com/v1/orders' => Http::response([
            'success' => true,
            'id' => 'bite-order-unknown-status',
            'status' => 'unknown_status',
            'courier' => [
                'company' => 'jne',
                'type' => 'reg',
            ],
        ], 200),
    ]);

    expect(
        fn() => app(BiteshipOrderService::class)
            ->createForOrder($order->id)
    )->toThrow(RuntimeException::class);

    $this->assertDatabaseHas('shipments', [
        'order_id' => $order->id,
        'status' => 'creation_failed',
        'provider_order_id' => null,
    ]);

    Http::assertSentCount(1);
});
