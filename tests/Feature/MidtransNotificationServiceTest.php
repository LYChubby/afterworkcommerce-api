<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\MidtransNotificationService;
use App\Services\OrderCancellationService;
use App\Services\OrderService;
use App\Models\ShippingQuote;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'midtrans.server_key' => 'SB-Mid-server-test-key',
    ]);

    $this->user = User::factory()->create();

    $this->warehouse = Warehouse::create([
        'name' => 'ATELIER Test Warehouse',
        'code' => 'ATL-WH-WEBHOOK',
        'recipient_name' => 'ATELIER Warehouse',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test No. 1',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'postal_code' => '40111',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $category = Category::create([
        'name' => 'Webhook Test Category',
        'slug' => 'webhook-test-category',
        'is_active' => true,
    ]);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'Webhook Test Product',
        'slug' => 'webhook-test-product',
        'description' => 'Product for webhook testing',
        'price' => 350000,
        'discount_price' => 299000,
        'is_active' => true,
    ]);

    $size = Size::create([
        'name' => 'Webhook M',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $color = Color::create([
        'name' => 'Webhook Black',
        'hex_code' => '#000000',
        'is_active' => true,
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'ATL-WEBHOOK-M-BLK',
        'stock_on_hand' => 100,
        'stock_reserved' => 0,
        'is_active' => true,
    ]);

    $this->address = [
        'recipient_name' => 'Test Customer',
        'phone' => '081234567890',
        'address_line' => 'Jl. Test Customer No. 10',
        'province' => 'Jawa Barat',
        'city' => 'Bandung',
        'postal_code' => '40111',
        'country' => 'Indonesia',
    ];
});

function createMidtransWebhookTestPayment($test): array
{

    Queue::fake();

    $warehouse = Warehouse::query()
        ->where('is_active', true)
        ->orderBy('id')
        ->firstOrFail();

    $items = [
        [
            'product_variant_id' => $test->variant->id,
            'quantity' => 2,
        ],
    ];

    $quote = ShippingQuote::create([
        'quote_group_uuid' => (string) Str::uuid(),
        'user_id' => $test->user->id,
        'warehouse_id' => $warehouse->id,
        'origin_snapshot' => [
            'city' => $warehouse->city,
            'province' => $warehouse->province,
            'postal_code' => $warehouse->postal_code,
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
        'courier_code' => 'test',
        'courier_name' => 'Test Courier',
        'shipping_fee' => 0,
        'currency' => 'IDR',
        'expires_at' => now()->addMinutes(15),
    ]);

    $order = app(OrderService::class)->createPendingOrder(
        user: $test->user,
        items: $items,
        shippingAddressSnapshot: $test->address,
        shippingQuoteUuid: $quote->uuid,
    );

    $payment = $order->payments()->create([
        'provider' => 'midtrans',
        'provider_order_id' => $order->order_number . '-P-TEST01',
        'gross_amount' => $order->grand_total,
        'status' => 'pending',
    ]);

    return [$order, $payment];
}

function createMidtransWebhookPayload(
    $payment,
    array $overrides = []
): array {
    $payload = array_merge([
        'order_id' => $payment->provider_order_id,
        'status_code' => '200',
        'gross_amount' => number_format(
            (float) $payment->gross_amount,
            2,
            '.',
            ''
        ),
        'transaction_status' => 'settlement',
        'transaction_id' => 'MIDTRANS-TEST-TRANSACTION-001',
        'payment_type' => 'bank_transfer',
        'fraud_status' => 'accept',
    ], $overrides);

    $payload['signature_key'] = hash(
        'sha512',
        $payload['order_id'] .
            $payload['status_code'] .
            $payload['gross_amount'] .
            config('midtrans.server_key')
    );

    return $payload;
}

test('valid settlement notification confirms payment and inventory reservation', function () {
    [$order, $payment] = createMidtransWebhookTestPayment($this);

    $payload = createMidtransWebhookPayload($payment);

    app(MidtransNotificationService::class)->handle($payload);

    $order->refresh();
    $payment->refresh();
    $this->variant->refresh();

    expect($payment->status)->toBe('paid')
        ->and($payment->paid_at)->not->toBeNull()
        ->and($order->payment_status)->toBe('paid')
        ->and($order->status)->toBe('processing')
        ->and((int) $this->variant->stock_on_hand)->toBe(98)
        ->and((int) $this->variant->stock_reserved)->toBe(0)
        ->and($order->inventoryReservations()->first()->status)
        ->toBe('confirmed');
});

test('invalid signature is rejected without changing payment or stock', function () {
    [$order, $payment] = createMidtransWebhookTestPayment($this);

    $payload = createMidtransWebhookPayload($payment);

    $payload['signature_key'] = 'invalid-signature';

    expect(fn() => app(MidtransNotificationService::class)->handle($payload))
        ->toThrow(InvalidArgumentException::class);

    $order->refresh();
    $payment->refresh();
    $this->variant->refresh();

    expect($payment->status)->toBe('pending')
        ->and($order->payment_status)->toBe('pending')
        ->and($order->status)->toBe('pending')
        ->and((int) $this->variant->stock_on_hand)->toBe(100)
        ->and((int) $this->variant->stock_reserved)->toBe(2);
});

test('notification with mismatched gross amount is rejected', function () {
    [$order, $payment] = createMidtransWebhookTestPayment($this);

    $payload = createMidtransWebhookPayload($payment, [
        'gross_amount' => '1000.00',
    ]);

    expect(fn() => app(MidtransNotificationService::class)->handle($payload))
        ->toThrow(RuntimeException::class);

    $order->refresh();
    $payment->refresh();
    $this->variant->refresh();

    expect($payment->status)->toBe('pending')
        ->and($order->payment_status)->toBe('pending')
        ->and((int) $this->variant->stock_on_hand)->toBe(100)
        ->and((int) $this->variant->stock_reserved)->toBe(2);
});

test('duplicate successful notification does not deduct stock twice', function () {
    [$order, $payment] = createMidtransWebhookTestPayment($this);

    $payload = createMidtransWebhookPayload($payment);

    $service = app(MidtransNotificationService::class);

    $service->handle($payload);
    $service->handle($payload);

    $order->refresh();
    $payment->refresh();
    $this->variant->refresh();

    expect($payment->status)->toBe('paid')
        ->and($order->status)->toBe('processing')
        ->and((int) $this->variant->stock_on_hand)->toBe(98)
        ->and((int) $this->variant->stock_reserved)->toBe(0)
        ->and($order->inventoryReservations()->where('status', 'confirmed')->count())
        ->toBe(1)
        ->and($this->variant->inventoryMovements()
            ->where('movement_type', 'stock_out')
            ->count())->toBe(1);
});

test('late successful payment is recorded without restoring expired stock', function () {
    [$order, $payment] = createMidtransWebhookTestPayment($this);

    $expiredAt = now()->subMinute();

    $order->update([
        'payment_deadline_at' => $expiredAt,
    ]);

    $order->inventoryReservations()->update([
        'expires_at' => $expiredAt,
    ]);

    app(OrderCancellationService::class)
        ->expirePendingOrder($order->id);

    $payload = createMidtransWebhookPayload($payment);

    app(MidtransNotificationService::class)->handle($payload);

    $order->refresh();
    $payment->refresh();
    $this->variant->refresh();

    expect($payment->status)->toBe('paid_late')
        ->and($order->status)->toBe('expired')
        ->and($order->payment_status)->toBe('pending')
        ->and((int) $this->variant->stock_on_hand)->toBe(100)
        ->and((int) $this->variant->stock_reserved)->toBe(0)
        ->and($order->inventoryReservations()->first()->status)
        ->toBe('expired');
});

test('capture notification with challenge fraud status does not confirm payment', function () {
    [$order, $payment] = createMidtransWebhookTestPayment($this);

    $payload = createMidtransWebhookPayload($payment, [
        'transaction_status' => 'capture',
        'fraud_status' => 'challenge',
    ]);

    app(MidtransNotificationService::class)->handle($payload);

    $order->refresh();
    $payment->refresh();
    $this->variant->refresh();

    expect($payment->status)->toBe('challenge')
        ->and($order->payment_status)->toBe('pending')
        ->and($order->status)->toBe('pending')
        ->and((int) $this->variant->stock_on_hand)->toBe(100)
        ->and((int) $this->variant->stock_reserved)->toBe(2);
});


test('midtrans webhook endpoint accepts a valid notification', function () {
    [$order, $payment] = createMidtransWebhookTestPayment($this);

    $payload = createMidtransWebhookPayload($payment);

    $response = $this->postJson(
        route('payments.midtrans.notification'),
        $payload
    );

    $response->assertOk()
        ->assertJson([
            'message' => 'Midtrans notification processed.',
        ]);

    expect($payment->fresh()->status)->toBe('paid')
        ->and($order->fresh()->status)->toBe('processing');
});

test('midtrans webhook endpoint rejects invalid signature', function () {
    [$order, $payment] = createMidtransWebhookTestPayment($this);

    $payload = createMidtransWebhookPayload($payment);
    $payload['signature_key'] = 'invalid-signature';

    $response = $this->postJson(
        route('payments.midtrans.notification'),
        $payload
    );

    $response->assertForbidden();

    expect($payment->fresh()->status)->toBe('pending')
        ->and($order->fresh()->status)->toBe('pending');
});
