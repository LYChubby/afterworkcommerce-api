
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

    $orderBefore = $this->shipment->order->fresh();

    $shippingCostBefore = $orderBefore->shipping_cost;
    $grandTotalBefore = $orderBefore->grand_total;

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => 18000,
    ]);

    $orderAfter = $this->shipment->order->fresh();

    expect($orderAfter->shipping_cost)->toBe($shippingCostBefore);
    expect($orderAfter->grand_total)->toBe($grandTotalBefore);
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

it('does not downgrade delivered shipment or order status from late webhook', function () {
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

    expect($shipment->fresh()->status)->toBe('delivered');

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


it('detects duplicate webhook even when payload key order is different', function () {
    $headers = [
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ];

    $firstResponse = $this->withHeaders($headers)
        ->postJson('/api/webhooks/biteship', [
            'event' => 'order.status',
            'order_id' => 'bite-order-test-001',
            'status' => 'delivered',
            'location' => 'Bandung',
        ]);

    $firstResponse->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $secondResponse = $this->withHeaders($headers)
        ->postJson('/api/webhooks/biteship', [
            'location' => 'Bandung',
            'status' => 'delivered',
            'order_id' => 'bite-order-test-001',
            'event' => 'order.status',
        ]);

    $secondResponse->assertOk()
        ->assertJson([
            'processed' => false,
        ]);

    $this->assertDatabaseCount('shipment_events', 1);
});


it('rejects webhook when status is not a string', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => ['delivered'],
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);
});

it('rejects webhook when location is not a string', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
        'location' => ['Bandung'],
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);
});

it('rejects webhook when courier tracking ID is not a string', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.waybill_id',
        'order_id' => 'bite-order-test-001',
        'courier_tracking_id' => ['TRACK-123'],
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);
});

it('rejects webhook when courier link is not a valid URL', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.waybill_id',
        'order_id' => 'bite-order-test-001',
        'courier_link' => 'not-a-valid-url',
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);
});

it('rejects webhook when shipping price is negative', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.price',
        'order_id' => 'bite-order-test-001',
        'price' => -1000,
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);
});

test('it does not downgrade order status when an older webhook arrives', function () {
    $shipment = $this->shipment->fresh();

    $shipment->update([
        'status' => 'picked',
    ]);

    $shipment->order->update([
        'status' => \App\Models\Order::STATUS_SHIPPED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => $shipment->provider_order_id,
        'status' => 'confirmed',
    ]);

    $response->assertOk();

    // Shipment tidak boleh mundur dari picked ke confirmed.
    expect($shipment->fresh()->status)
        ->toBe('picked');

    // Order tidak boleh mundur dari shipped ke processing.
    expect($shipment->order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_SHIPPED);
});

test('it preserves existing tracking information when webhook sends empty values', function () {
    $this->shipment->update([
        'tracking_id' => 'TRACK-OLD-123',
        'waybill_id' => 'WAYBILL-OLD-123',
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => $this->shipment->provider_order_id,
        'status' => 'picked',
        'courier_tracking_id' => '',
        'courier_waybill_id' => '   ',
    ]);

    $response->assertOk();

    expect($this->shipment->fresh()->tracking_id)
        ->toBe('TRACK-OLD-123');

    expect($this->shipment->fresh()->waybill_id)
        ->toBe('WAYBILL-OLD-123');
});

test('it allows customers to view their own shipment tracking', function () {
    $order = $this->shipment->order;

    $response = $this->actingAs($order->user, 'sanctum')
        ->getJson("/api/orders/{$order->id}/shipment");

    $response
        ->assertOk()
        ->assertJsonPath('message', 'Informasi pelacakan berhasil diambil.')
        ->assertJsonPath('data.order.uuid', $order->uuid)
        ->assertJsonPath('data.shipment.provider', 'biteship')
        ->assertJsonPath('data.shipment.status', $this->shipment->status)
        ->assertJsonStructure([
            'data' => [
                'order',
                'shipment' => [
                    'provider',
                    'courier_name',
                    'tracking_id',
                    'waybill_id',
                    'tracking_url',
                    'status',
                ],
                'events',
            ],
        ]);
});

test('it prevents customers from viewing another customers shipment tracking', function () {
    $order = $this->shipment->order;

    $owner = $order->user;
    $anotherUser = \App\Models\User::factory()->create();

    $order->update([
        'user_id' => $anotherUser->id,
    ]);

    $response = $this->actingAs($owner, 'sanctum')
        ->getJson("/api/orders/{$order->id}/shipment");

    $response->assertNotFound();
});

test('it returns null shipment data when order has no shipment', function () {
    $order = $this->shipment->order;

    $this->shipment->delete();

    $response = $this->actingAs($order->user, 'sanctum')
        ->getJson("/api/orders/{$order->id}/shipment");

    $response
        ->assertOk()
        ->assertJsonPath(
            'message',
            'Informasi pengiriman belum tersedia.'
        )
        ->assertJsonPath('data', null);
});

test('it rejects unauthenticated customers from viewing shipment tracking', function () {
    $order = $this->shipment->order;

    $response = $this->getJson(
        "/api/orders/{$order->id}/shipment"
    );

    $response->assertUnauthorized();
});



test('it returns shipment events ordered from newest to oldest', function () {
    $order = $this->shipment->order;

    $olderEventId = \Illuminate\Support\Facades\DB::table('shipment_events')
        ->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'shipment_id' => $this->shipment->id,
            'event_type' => 'order.status',
            'status' => 'picked',
            'description' => 'Package picked up',
            'location' => 'Bandung',
            'occurred_at' => now()->subHours(2),
            'event_hash' => hash('sha256', 'older-event'),
            'payload' => json_encode([
                'internal_reference' => 'PRIVATE-OLDER',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    $newerEventId = \Illuminate\Support\Facades\DB::table('shipment_events')
        ->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'shipment_id' => $this->shipment->id,
            'event_type' => 'order.status',
            'status' => 'inTransit',
            'description' => 'Package is in transit',
            'location' => 'Jakarta',
            'occurred_at' => now()->subHour(),
            'event_hash' => hash('sha256', 'newer-event'),
            'payload' => json_encode([
                'internal_reference' => 'PRIVATE-NEWER',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    $response = $this->actingAs($order->user, 'sanctum')
        ->getJson("/api/orders/{$order->id}/shipment");

    $response
        ->assertOk()
        ->assertJsonPath('data.events.0.id', $newerEventId)
        ->assertJsonPath('data.events.0.status', 'inTransit')
        ->assertJsonPath('data.events.0.location', 'Jakarta')
        ->assertJsonPath('data.events.1.id', $olderEventId)
        ->assertJsonPath('data.events.1.status', 'picked')
        ->assertJsonPath('data.events.1.location', 'Bandung')
        ->assertJsonMissingPath('data.events.0.payload')
        ->assertJsonMissingPath('data.events.0.event_hash');
});


test('it does not expose internal shipment provider data in tracking response', function () {
    $order = $this->shipment->order;

    $this->shipment->update([
        'provider_response' => [
            'api_secret' => 'PRIVATE-BITESHIP-SECRET',
            'internal_reference' => 'PRIVATE-PROVIDER-REFERENCE',
        ],
    ]);

    \Illuminate\Support\Facades\DB::table('shipment_events')
        ->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'shipment_id' => $this->shipment->id,
            'event_type' => 'order.status',
            'status' => 'picked',
            'description' => 'Package picked up',
            'location' => 'Bandung',
            'occurred_at' => now(),
            'event_hash' => hash('sha256', 'private-test-event'),
            'payload' => json_encode([
                'secret' => 'PRIVATE-WEBHOOK-PAYLOAD',
                'internal_reference' => 'PRIVATE-EVENT-REFERENCE',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    $response = $this->actingAs($order->user, 'sanctum')
        ->getJson("/api/orders/{$order->id}/shipment");

    $response
        ->assertOk()
        ->assertJsonMissingPath('data.shipment.provider_response')
        ->assertJsonMissingPath('data.events.0.payload')
        ->assertJsonMissingPath('data.events.0.event_hash')
        ->assertDontSee('PRIVATE-BITESHIP-SECRET')
        ->assertDontSee('PRIVATE-PROVIDER-REFERENCE')
        ->assertDontSee('PRIVATE-WEBHOOK-PAYLOAD')
        ->assertDontSee('PRIVATE-EVENT-REFERENCE');
});

it('rejects webhook when shipping price is decimal', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.price',
        'order_id' => 'bite-order-test-001',
        'price' => 18000.5,
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => null,
    ]);
});

it('rejects webhook when shipping price exceeds PHP_INT_MAX', function () {
    $price = PHP_INT_MAX . '0';

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.price',
        'order_id' => 'bite-order-test-001',
        'price' => $price,
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => null,
    ]);
});

it('accepts shipping price equal to PHP_INT_MAX', function () {
    $price = PHP_INT_MAX;

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.price',
        'order_id' => 'bite-order-test-001',
        'price' => $price,
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => $price,
    ]);
});

it('rejects webhook when order ID contains only whitespace', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => '   ',
        'status' => 'delivered',
    ]);

    $response->assertUnprocessable();

    $response->assertJson([
        'message' => 'Order ID Biteship tidak tersedia.',
    ]);

    $this->assertDatabaseCount('shipment_events', 0);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'confirmed',
    ]);
});

it('accepts order_price as an alias for shipping price', function () {
    $price = 25000;

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.price',
        'order_id' => 'bite-order-test-001',
        'order_price' => $price,
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => $price,
    ]);
});

it('accepts numeric string as shipping price', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.price',
        'order_id' => 'bite-order-test-001',
        'order_price' => '25000',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => 25000,
    ]);
});

it('rejects shipping price webhook when price fields are missing', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.price',
        'order_id' => 'bite-order-test-001',
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => null,
    ]);
});

it('does not duplicate shipment events when shipping price webhook is repeated', function () {
    $payload = [
        'event' => 'order.price',
        'order_id' => 'bite-order-test-001',
        'price' => 25000,
    ];

    $headers = [
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ];

    $firstResponse = $this->withHeaders($headers)
        ->postJson('/api/webhooks/biteship', $payload);

    $firstResponse->assertOk();

    $this->assertDatabaseCount('shipment_events', 1);

    $secondResponse = $this->withHeaders($headers)
        ->postJson('/api/webhooks/biteship', $payload);

    $secondResponse->assertOk();

    $this->assertDatabaseCount('shipment_events', 1);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => 25000,
    ]);
});

it('updates shipping fee when a new price webhook is received', function () {
    $headers = [
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ];

    $firstResponse = $this->withHeaders($headers)
        ->postJson('/api/webhooks/biteship', [
            'event' => 'order.price',
            'order_id' => 'bite-order-test-001',
            'price' => 25000,
        ]);

    $firstResponse->assertOk();

    $secondResponse = $this->withHeaders($headers)
        ->postJson('/api/webhooks/biteship', [
            'event' => 'order.price',
            'order_id' => 'bite-order-test-001',
            'price' => 30000,
        ]);

    $secondResponse->assertOk();

    $this->assertDatabaseCount('shipment_events', 2);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => 30000,
    ]);
});

it('does not process webhook when shipment order ID is unknown', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.price',
        'order_id' => 'unknown-bite-order-999',
        'price' => 25000,
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'actual_shipping_fee' => null,
    ]);
});

it('records unknown shipment status without changing shipment status', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'unknown_future_status',
    ]);

    $originalOrderStatus = $this->shipment->order->status;

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipment_events', [
        'shipment_id' => $this->shipment->id,
        'status' => 'unknown_future_status',
    ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'confirmed',
    ]);

    expect($this->shipment->order->fresh()->status)
        ->toBe($originalOrderStatus);
});

it('rejects shipment status containing only whitespace', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => '   ',
    ]);

    $response->assertUnprocessable();

    $this->assertDatabaseCount('shipment_events', 0);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'confirmed',
    ]);
});

it('normalizes shipment status before processing webhook', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => ' IN_TRANSIT ',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'in_transit',
    ]);
});

it('handles return in transit status correctly', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'returnInTransit',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'returnInTransit',
    ]);

    $this->assertDatabaseHas('shipment_events', [
        'shipment_id' => $this->shipment->id,
        'status' => 'returnInTransit',
    ]);

    expect($this->shipment->order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('does not downgrade return in transit shipment from late delivered webhook', function () {
    $this->shipment->update([
        'status' => 'returnInTransit',
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => $this->shipment->provider_order_id,
        'status' => 'delivered',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    expect($this->shipment->fresh()->status)
        ->toBe('returnInTransit');
});

it('does not downgrade returned order status from late delivered webhook', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_RETURNED,
    ]);

    $this->shipment->update([
        'status' => 'returned',
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    expect($this->shipment->fresh()->status)->toBe('returned');

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('does not downgrade cancelled order status from late delivered webhook', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_CANCELLED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_CANCELLED);
});

it('does not downgrade expired order status from late delivered webhook', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_EXPIRED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_EXPIRED);
});

it('updates delivered order to returned when return webhook arrives', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_DELIVERED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'returned',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('updates shipment and records event when shipment is returned', function () {
    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'returned',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'returned',
    ]);

    $this->assertDatabaseHas('shipment_events', [
        'shipment_id' => $this->shipment->id,
        'status' => 'returned',
    ]);

    expect($this->shipment->order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('does not create duplicate shipment events for repeated returned webhook', function () {
    $payload = [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'returned',
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

    expect(
        \App\Models\ShipmentEvent::where(
            'shipment_id',
            $this->shipment->id
        )
            ->where('status', 'returned')
            ->count()
    )->toBe(1);

    expect($this->shipment->order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('preserves returned order status when return in transit webhook arrives', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_RETURNED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'returnInTransit',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'returnInTransit',
    ]);

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('does not create duplicate events for repeated return in transit webhook', function () {
    $payload = [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'returnInTransit',
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

    expect(
        \App\Models\ShipmentEvent::where(
            'shipment_id',
            $this->shipment->id
        )
            ->where('status', 'returnInTransit')
            ->count()
    )->toBe(1);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'returnInTransit',
    ]);

    expect($this->shipment->order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('preserves returned order status when late in transit webhook arrives', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_RETURNED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'in_transit',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('preserves returned order status when late delivered webhook arrives', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_RETURNED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('preserves existing delivered timestamp when late delivered webhook arrives', function () {
    $order = $this->shipment->order;

    $deliveredAt = now()->subDays(2)->startOfSecond();

    $this->shipment->update([
        'status' => 'returned',
        'delivered_at' => $deliveredAt,
    ]);

    $order->update([
        'status' => \App\Models\Order::STATUS_RETURNED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    expect($this->shipment->fresh()->delivered_at->toDateTimeString())
        ->toBe($deliveredAt->toDateTimeString());

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_RETURNED);
});

it('preserves cancelled order status when returned webhook arrives', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_CANCELLED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'returned',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_CANCELLED);
});

it('preserves expired order status when returned webhook arrives', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_EXPIRED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'returned',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_EXPIRED);
});

it('preserves cancelled order status when return in transit webhook arrives', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_CANCELLED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'returnInTransit',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'returnInTransit',
    ]);

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_CANCELLED);
});

it('preserves expired order status when return in transit webhook arrives', function () {
    $order = $this->shipment->order;

    $order->update([
        'status' => \App\Models\Order::STATUS_EXPIRED,
    ]);

    $response = $this->withHeaders([
        'X-AfterWork-Biteship-Key' => 'test-biteship-secret',
    ])->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'returnInTransit',
    ]);

    $response->assertOk()
        ->assertJson([
            'processed' => true,
        ]);

    $this->assertDatabaseHas('shipments', [
        'id' => $this->shipment->id,
        'status' => 'returnInTransit',
    ]);

    expect($order->fresh()->status)
        ->toBe(\App\Models\Order::STATUS_EXPIRED);
});

it('rejects webhook when header configuration is empty', function () {
    config([
        'biteship.webhook_header' => '',
        'biteship.webhook_secret' => 'test-biteship-secret',
    ]);

    $response = $this->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertServiceUnavailable()
        ->assertJson([
            'message' => 'Webhook authentication is not configured.',
        ]);
});

it('rejects webhook when secret configuration is empty', function () {
    config([
        'biteship.webhook_header' => 'X-AfterWork-Biteship-Key',
        'biteship.webhook_secret' => '',
    ]);

    $response = $this->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertServiceUnavailable()
        ->assertJson([
            'message' => 'Webhook authentication is not configured.',
        ]);
});

it('rejects webhook when secret configuration contains only whitespace', function () {
    config([
        'biteship.webhook_header' => 'X-AfterWork-Biteship-Key',
        'biteship.webhook_secret' => '   ',
    ]);

    $response = $this->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertServiceUnavailable()
        ->assertJson([
            'message' => 'Webhook authentication is not configured.',
        ]);
});

it('rejects webhook when header configuration contains only whitespace', function () {
    config([
        'biteship.webhook_header' => '   ',
        'biteship.webhook_secret' => 'test-biteship-secret',
    ]);

    $response = $this->postJson('/api/webhooks/biteship', [
        'event' => 'order.status',
        'order_id' => 'bite-order-test-001',
        'status' => 'delivered',
    ]);

    $response->assertServiceUnavailable()
        ->assertJson([
            'message' => 'Webhook authentication is not configured.',
        ]);
});

it('trims whitespace from the configured webhook header name', function () {
    config([
        'biteship.webhook_header' => ' X-AfterWork-Biteship-Key ',
        'biteship.webhook_secret' => 'test-biteship-secret',
    ]);

    $response = $this
        ->withHeader(
            'X-AfterWork-Biteship-Key',
            'test-biteship-secret'
        )
        ->postJson('/api/webhooks/biteship', [
            'event' => 'order.status',
            'order_id' => 'bite-order-test-001',
            'status' => 'delivered',
        ]);

    $response->assertOk();
});
