
<?php

use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'biteship.webhook_header' => 'X-AfterWork-Biteship-Key',
        'biteship.webhook_secret' => 'test-biteship-secret',
    ]);

    $this->user = User::factory()->create();

    $this->warehouse = Warehouse::create([
        'name' => 'Test Warehouse',
        'code' => 'TEST-WH',
        'recipient_name' => 'ATELIER',
        'phone' => '081234567890',
        'address_line' => 'Jl Test 1',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'district' => 'Coblong',
        'postal_code' => '40111',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $this->order = Order::create([
        'order_number' => 'ATL-WEBHOOK-TEST-001',
        'user_id' => $this->user->id,
        'warehouse_id' => $this->warehouse->id,
        'shipping_address_snapshot' => [
            'recipient_name' => 'Test Customer',
            'phone' => '081234567890',
            'address_line' => 'Jl Test 2',
            'province' => 'Jawa Barat',
            'city' => 'Bandung',
            'postal_code' => '40132',
            'country' => 'Indonesia',
        ],
        'status' => 'processing',
        'payment_status' => 'paid',
        'subtotal' => 350000,
        'item_discount_total' => 0,
        'shipping_cost' => 15000,
        'grand_total' => 365000,
        'currency' => 'IDR',
    ]);

    $this->shipment = Shipment::create([
        'order_id' => $this->order->id,
        'provider' => 'biteship',
        'provider_order_id' => 'bite-order-test-001',
        'tracking_id' => 'TRACK-INITIAL',
        'waybill_id' => 'RESI-INITIAL',
        'courier_code' => 'jne',
        'courier_service_code' => 'reg',
        'courier_name' => 'JNE',
        'status' => 'confirmed',
    ]);
});

test('it rejects webhook with invalid secret', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'wrong-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertUnauthorized();

    $this->assertDatabaseCount('shipment_events', 0);
});

test('it processes valid shipment status webhook', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
        'location' => 'Bandung',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'delivered',
    ]);

    $this->assertDatabaseHas('shipment_events', [
        'shipment_id' => $this->shipment->id,
        'event_type' => 'order.status',
        'status' => 'delivered',
        'location' => 'Bandung',
    ]);

    expect(
        $this->shipment->fresh()->delivered_at
    )->not->toBeNull();
});

test('it does not process duplicate webhook twice', function () {
    $payload = [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
        'location' => 'Bandung',
    ];

    $headers = [
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ];

    $firstResponse = $this->withHeaders($headers)
        ->postJson('/api/webhooks/biteship', $payload);

    $firstResponse->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $secondResponse = $this->withHeaders($headers)
        ->postJson('/api/webhooks/biteship', $payload);

    $secondResponse->assertOk()
        ->assertJson([
            'processed' => false,
        ]);

    $this->assertDatabaseCount('shipment_events', 1);
});

test('it updates shipment tracking information from waybill webhook', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.waybill_id',
        'order_id' => 'bite-order-test-001',
        'courier_waybill_id' => 'JNE-NEW-12345',
        'courier_tracking_id' => 'TRACK-NEW-12345',
        'courier_link' => 'https://example.com/tracking/12345',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'waybill_id' => 'JNE-NEW-12345',
        'tracking_id' => 'TRACK-NEW-12345',
        'tracking_url' => 'https://example.com/tracking/12345',
    ]);
});

test('it updates actual shipping fee from price webhook', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.price',
        'order_id' => 'bite-order-test-001',
        'price' => 18000,
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => 18000,
    ]);
});

test('it rejects invalid webhook event', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.unknown',
        'order_id' => 'bite-order-test-001',
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);
});

test('it rejects webhook without json content type', function () {
    $response = $this->call(
        'POST',
        '/api/webhooks/biteship',
        [],
        [],
        [],
        [
            'HTTP_X_AFTERWORK_BITESHIP_KEY' => 'test-biteship-secret',
            'CONTENT_TYPE' => 'text/plain',
        ],
        'not json'
    );

    $response->assertStatus(415);

    $this->assertDatabaseCount('shipment_events', 0);
});

test('it rejects webhook when secret is not configured', function () {
    config([
        'biteship.webhook_secret' => null,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertStatus(503);

    $this->assertDatabaseCount('shipment_events', 0);
});

it('updates order status to delivered when shipment is delivered', function () {
    $shipment = $this->shipment->fresh();

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => $shipment->provider_order_id,
        'status' => 'delivered',
        'location' => 'Bandung',
    ]);

    $response->assertOk();

    expect($shipment->fresh()->status)->toBe('delivered');

    expect($shipment->order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_DELIVERED);
});

it('updates order status to returned when shipment is returned', function () {
    $shipment = $this->shipment->fresh();

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => $shipment->provider_order_id,
        'status' => 'returned',
    ]);

    $response->assertOk();

    expect($shipment->fresh()->status)->toBe('returned');

    expect($shipment->order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('does not downgrade delivered order status from late webhook', function () {
    $shipment = $this->shipment->fresh();

    $shipment->update([
        'status' => 'delivered',
    ]);

    $shipment->order->update([
        'status' => \App\Models\Order::STATUS_DELIVERED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => $shipment->provider_order_id,
        'status' => 'inTransit',
    ]);

    $response->assertOk();

    expect($shipment->fresh()->status)->toBe('inTransit');

    expect($shipment->order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_DELIVERED);
});

it('does not change cancelled order status from shipment webhook', function () {
    $shipment = $this->shipment->fresh();

    $shipment->order->update([
        'status' => \App\Models\Order::STATUS_CANCELLED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => $shipment->provider_order_id,
        'status' => 'delivered',
    ]);

    $response->assertOk();

    expect($shipment->order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_CANCELLED);
});


it('maps Biteship courier statuses to shipped order status', function (string $shipmentStatus) {
    $shipment = $this->shipment->fresh();

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => $shipment->provider_order_id,
        'status' => $shipmentStatus,
    ]);

    $response->assertOk();

    expect($shipment->fresh()->status)->toBe($shipmentStatus);

    expect($shipment->order->fresh()->status)
        ->toBe(Order::STATUS_SHIPPED);
})->with([
    'picking_up',
    'picked',
    'dropping_off',
]);
