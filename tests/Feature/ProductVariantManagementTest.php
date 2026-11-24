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
use Illuminate\Support\Str;

const PRODUCT_VARIANTS_ENDPOINT = '/api/admin/products';

function createVariantTestAdmin(): User
{
    return User::factory()->create([
        'role' => 'admin',
    ]);
}

function createVariantTestCustomer(): User
{
    return User::factory()->create([
        'role' => 'customer',
    ]);
}

function createVariantTestProduct(): Product
{
    $category = Category::create([
        'name' => 'Variant Category',
        'slug' => 'variant-category-'.Str::lower(Str::random(8)),
        'is_active' => true,
    ]);

    return Product::create([
        'category_id' => $category->id,
        'name' => 'Variant Test Product',
        'slug' => 'variant-product-'.Str::lower(Str::random(8)),
        'description' => 'Product for variant tests.',
        'price' => 350000,
        'is_active' => true,
    ]);
}

function createVariantTestSize(array $attributes = []): Size
{
    return Size::create(array_merge([
        'name' => 'Size '.Str::upper(Str::random(6)),
        'sort_order' => 1,
        'is_active' => true,
    ], $attributes));
}

function createVariantTestColor(array $attributes = []): Color
{
    return Color::create(array_merge([
        'name' => 'Color '.Str::upper(Str::random(6)),
        'hex_code' => '#112233',
        'is_active' => true,
    ], $attributes));
}

function createVariantTestRecord(
    Product $product,
    array $attributes = []
): ProductVariant {
    $size = createVariantTestSize();
    $color = createVariantTestColor();

    return ProductVariant::create(array_merge([
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'SKU-'.Str::upper(Str::random(10)),
        'stock_on_hand' => 0,
        'stock_reserved' => 0,
        'is_active' => true,
    ], $attributes));
}

it('rejects guests from all admin product variant endpoints', function () {
    $product = createVariantTestProduct();
    $variant = createVariantTestRecord($product);

    $this->getJson(PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants")
        ->assertUnauthorized();

    $this->postJson(PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants")
        ->assertUnauthorized();

    $this->getJson(
        PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}"
    )->assertUnauthorized();

    $this->putJson(
        PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}"
    )->assertUnauthorized();

    $this->deleteJson(
        PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}"
    )->assertUnauthorized();
});

it('forbids customers from all admin product variant endpoints', function () {
    $customer = createVariantTestCustomer();
    $product = createVariantTestProduct();
    $variant = createVariantTestRecord($product);

    $this->actingAs($customer, 'sanctum');

    $this->getJson(PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants")
        ->assertForbidden();

    $this->postJson(PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants")
        ->assertForbidden();

    $this->getJson(
        PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}"
    )->assertForbidden();

    $this->putJson(
        PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}"
    )->assertForbidden();

    $this->deleteJson(
        PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}"
    )->assertForbidden();
});

it('allows admins to retrieve product variants', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();
    $variant = createVariantTestRecord($product);

    $this->actingAs($admin, 'sanctum')
        ->getJson(PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.uuid', $variant->uuid)
        ->assertJsonPath('data.0.sku', $variant->sku)
        ->assertJsonPath('data.0.stock_on_hand', 0)
        ->assertJsonPath('data.0.stock_reserved', 0)
        ->assertJsonPath('data.0.available_stock', 0)
        ->assertJsonPath('data.0.is_active', true);
});

it('allows admins to create a product variant', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();
    $size = createVariantTestSize();
    $color = createVariantTestColor();

    $this->actingAs($admin, 'sanctum')
        ->postJson(PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants", [
            'size_uuid' => $size->uuid,
            'color_uuid' => $color->uuid,
            'sku' => 'VARIANT-CREATE-001',
        ])
        ->assertCreated()
        ->assertJsonPath('data.sku', 'VARIANT-CREATE-001')
        ->assertJsonPath('data.stock_on_hand', 0)
        ->assertJsonPath('data.stock_reserved', 0)
        ->assertJsonPath('data.available_stock', 0);

    $this->assertDatabaseHas('product_variants', [
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'VARIANT-CREATE-001',
        'stock_on_hand' => 0,
        'stock_reserved' => 0,
    ]);
});

it('rejects duplicate SKUs when creating a variant', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();
    $existing = createVariantTestRecord($product, [
        'sku' => 'DUPLICATE-VARIANT-SKU',
    ]);

    $size = createVariantTestSize();
    $color = createVariantTestColor();

    $this->actingAs($admin, 'sanctum')
        ->postJson(PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants", [
            'size_uuid' => $size->uuid,
            'color_uuid' => $color->uuid,
            'sku' => $existing->sku,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sku');
});

it('rejects duplicate size and color combinations', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();
    $existing = createVariantTestRecord($product);

    $this->actingAs($admin, 'sanctum')
        ->postJson(PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants", [
            'size_uuid' => $existing->size->uuid,
            'color_uuid' => $existing->color->uuid,
            'sku' => 'VARIANT-DUPLICATE-COMBINATION',
        ])
        ->assertUnprocessable();

    $this->assertDatabaseCount('product_variants', 1);
});

it('allows admins to update a product variant', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();
    $variant = createVariantTestRecord($product);

    $this->actingAs($admin, 'sanctum')
        ->putJson(
            PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}",
            [
                'sku' => 'VARIANT-UPDATED-001',
                'is_active' => false,
            ]
        )
        ->assertOk()
        ->assertJsonPath('data.sku', 'VARIANT-UPDATED-001')
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
        'sku' => 'VARIANT-UPDATED-001',
        'is_active' => false,
    ]);
});

it('does not allow variant CRUD endpoints to change stock directly', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();
    $variant = createVariantTestRecord($product);

    $endpoint = PRODUCT_VARIANTS_ENDPOINT
        ."/{$product->uuid}/variants/{$variant->uuid}";

    $this->actingAs($admin, 'sanctum')
        ->putJson($endpoint, [
            'stock_on_hand' => 100,
            'stock_reserved' => 50,
        ])
        ->assertOk();

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
        'stock_on_hand' => 0,
        'stock_reserved' => 0,
    ]);
});

it('prevents admins from accessing a variant belonging to another product', function () {
    $admin = createVariantTestAdmin();
    $productA = createVariantTestProduct();
    $productB = createVariantTestProduct();
    $variant = createVariantTestRecord($productB);

    $this->actingAs($admin, 'sanctum')
        ->getJson(
            PRODUCT_VARIANTS_ENDPOINT."/{$productA->uuid}/variants/{$variant->uuid}"
        )
        ->assertNotFound();
});

it('returns not found for an unknown product UUID', function () {
    $admin = createVariantTestAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson(PRODUCT_VARIANTS_ENDPOINT.'/'.Str::uuid().'/variants')
        ->assertNotFound();
});

it('prevents admins from changing a variant combination with inventory reservations', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();
    $variant = createVariantTestRecord($product);

    $variant->load(['size', 'color', 'product']);

    $warehouse = Warehouse::create([
        'name' => 'Test Warehouse',
        'code' => 'WH-VARIANT-001',
        'recipient_name' => 'Test Warehouse Recipient',
        'phone' => '081234567890',
        'address_line' => 'Test Warehouse Address',
        'province' => 'Central Java',
        'city' => 'Magelang',
        'postal_code' => '56172',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $order = Order::create([
        'order_number' => 'ORD-VARIANT-RESERVATION-001',
        'user_id' => createVariantTestCustomer()->id,
        'warehouse_id' => $warehouse->id,
        'shipping_address_snapshot' => [
            'recipient_name' => 'Test Customer',
            'phone' => '081234567890',
            'address_line' => 'Test Address',
        ],
    ]);

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'product_name' => $product->name,
        'product_slug' => $product->slug,
        'sku' => $variant->sku,
        'size_name' => $variant->size->name,
        'color_name' => $variant->color->name,
        'color_hex_code' => $variant->color->hex_code,
        'unit_price' => 350000,
        'unit_discount' => 0,
        'quantity' => 1,
        'line_total' => 350000,
    ]);

    InventoryReservation::create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'status' => 'active',
        'expires_at' => now()->addMinutes(15),
    ]);

    $newSize = createVariantTestSize();
    $newColor = createVariantTestColor();

    $this->actingAs($admin, 'sanctum')
        ->patchJson(
            PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}",
            [
                'size_uuid' => $newSize->uuid,
                'color_uuid' => $newColor->uuid,
            ]
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant');

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
        'size_id' => $variant->size_id,
        'color_id' => $variant->color_id,
    ]);
});

it('prevents admins from deleting a product variant with available stock', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();

    $variant = createVariantTestRecord($product, [
        'stock_on_hand' => 10,
        'stock_reserved' => 0,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(
            PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}"
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant');

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
        'stock_on_hand' => 10,
        'stock_reserved' => 0,
    ]);
});

it('prevents admins from deleting a product variant with inventory movements', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();
    $variant = createVariantTestRecord($product);

    $variant->load(['size', 'color', 'product']);

    $warehouse = Warehouse::create([
        'name' => 'Test Warehouse',
        'code' => 'WH-VARIANT-MOVEMENT-001',
        'recipient_name' => 'Test Warehouse Recipient',
        'phone' => '081234567890',
        'address_line' => 'Test Warehouse Address',
        'province' => 'Central Java',
        'city' => 'Magelang',
        'postal_code' => '56172',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    InventoryMovement::create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'movement_type' => 'stock_in',
        'quantity' => 10,

        'stock_on_hand_before' => 0,
        'stock_on_hand_after' => 10,
        'stock_reserved_before' => 0,
        'stock_reserved_after' => 0,

        'reference_type' => null,
        'reference_id' => null,

        'notes' => 'Initial stock',
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(
            PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}"
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant');

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
    ]);
});

it('prevents admins from deleting a product variant with inventory reservations', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();
    $variant = createVariantTestRecord($product);

    $variant->load(['size', 'color']);

    $warehouse = Warehouse::create([
        'name' => 'Test Warehouse',
        'code' => 'WH-VARIANT-RESERVATION-DELETE-001',
        'recipient_name' => 'Test Warehouse Recipient',
        'phone' => '081234567890',
        'address_line' => 'Test Warehouse Address',
        'province' => 'Central Java',
        'city' => 'Magelang',
        'postal_code' => '56172',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    $order = Order::create([
        'order_number' => 'ORD-VARIANT-RESERVATION-DELETE-001',
        'user_id' => createVariantTestCustomer()->id,
        'warehouse_id' => $warehouse->id,
        'shipping_address_snapshot' => [
            'recipient_name' => 'Test Customer',
            'phone' => '081234567890',
            'address_line' => 'Test Address',
        ],
    ]);

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'product_name' => $product->name,
        'product_slug' => $product->slug,
        'sku' => $variant->sku,
        'size_name' => $variant->size->name,
        'color_name' => $variant->color->name,
        'color_hex_code' => $variant->color->hex_code,
        'unit_price' => 350000,
        'unit_discount' => 0,
        'quantity' => 1,
        'line_total' => 350000,
    ]);

    InventoryReservation::create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'status' => 'released',
        'expires_at' => now()->subMinutes(15),
        'released_at' => now(),
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(
            PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}"
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant');

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
    ]);
});

it('prevents admins from changing a variant combination with inventory movements', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();
    $variant = createVariantTestRecord($product);

    $variant->load(['size', 'color']);

    $warehouse = Warehouse::create([
        'name' => 'Test Warehouse',
        'code' => 'WH-VARIANT-MOVEMENT-UPDATE-001',
        'recipient_name' => 'Test Warehouse Recipient',
        'phone' => '081234567890',
        'address_line' => 'Test Warehouse Address',
        'province' => 'Central Java',
        'city' => 'Magelang',
        'postal_code' => '56172',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);

    InventoryMovement::create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'movement_type' => 'stock_out',
        'quantity' => 10,
        'stock_on_hand_before' => 10,
        'stock_on_hand_after' => 0,
        'stock_reserved_before' => 0,
        'stock_reserved_after' => 0,
        'notes' => 'Historical stock movement',
        'created_by' => $admin->id,
    ]);

    $newSize = createVariantTestSize();
    $newColor = createVariantTestColor();

    $this->actingAs($admin, 'sanctum')
        ->patchJson(
            PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}",
            [
                'size_uuid' => $newSize->uuid,
                'color_uuid' => $newColor->uuid,
            ]
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant');

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
        'size_id' => $variant->size_id,
        'color_id' => $variant->color_id,
    ]);
});

it('prevents admins from changing a variant combination with available stock', function () {
    $admin = createVariantTestAdmin();
    $product = createVariantTestProduct();

    $variant = createVariantTestRecord($product, [
        'stock_on_hand' => 10,
        'stock_reserved' => 0,
    ]);

    $newSize = createVariantTestSize();
    $newColor = createVariantTestColor();

    $this->actingAs($admin, 'sanctum')
        ->patchJson(
            PRODUCT_VARIANTS_ENDPOINT."/{$product->uuid}/variants/{$variant->uuid}",
            [
                'size_uuid' => $newSize->uuid,
                'color_uuid' => $newColor->uuid,
            ]
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant');

    $this->assertDatabaseHas('product_variants', [
        'id' => $variant->id,
        'size_id' => $variant->size_id,
        'color_id' => $variant->color_id,
        'stock_on_hand' => 10,
        'stock_reserved' => 0,
    ]);
});
