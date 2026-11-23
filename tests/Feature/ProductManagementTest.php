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

const ADMIN_PRODUCTS_ENDPOINT = '/api/admin/products';

function createProductAdmin(): User
{
    return User::factory()->create([
        'role' => 'admin',
    ]);
}

function createProductCustomer(): User
{
    return User::factory()->create([
        'role' => 'customer',
    ]);
}

function createProductCategory(array $attributes = []): Category
{
    return Category::create(array_merge([
        'name' => 'Tops',
        'slug' => 'tops',
        'description' => 'Kategori atasan.',
        'is_active' => true,
    ], $attributes));
}

function createManagedProduct(Category $category, array $attributes = []): Product
{
    return Product::create(array_merge([
        'category_id' => $category->id,
        'name' => 'Essential Shirt',
        'slug' => 'essential-shirt-'.$category->id,
        'description' => 'Essential shirt product.',
        'price' => 350000,
        'discount_price' => null,
        'is_active' => true,
        'weight_grams' => 300,
        'length_cm' => 30,
        'width_cm' => 25,
        'height_cm' => 3,
    ], $attributes));
}

function createProductVariant(
    Product $product,
    array $attributes = []
): ProductVariant {
    $size = Size::create([
        'name' => 'Size '.fake()->unique()->numerify('##'),
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $color = Color::create([
        'name' => 'Color '.fake()->unique()->numerify('##'),
        'hex_code' => '#000000',
        'is_active' => true,
    ]);

    return ProductVariant::create(array_merge([
        'product_id' => $product->id,
        'size_id' => $size->id,
        'color_id' => $color->id,
        'sku' => 'SKU-'.strtoupper(fake()->unique()->bothify('????-####')),
        'stock_on_hand' => 0,
        'stock_reserved' => 0,
        'is_active' => true,
    ], $attributes));
}

function createProductWarehouse(): Warehouse
{
    return Warehouse::create([
        'name' => 'Main Warehouse',
        'code' => 'WH-'.strtoupper(fake()->unique()->bothify('????##')),
        'recipient_name' => 'Warehouse Admin',
        'phone' => '081234567890',
        'address_line' => 'Jl. Gudang No. 1',
        'province' => 'DKI Jakarta',
        'city' => 'Jakarta Selatan',
        'district' => 'Kebayoran Baru',
        'postal_code' => '12110',
        'country' => 'Indonesia',
        'is_active' => true,
    ]);
}

it('rejects guests from all admin product endpoints', function () {
    $category = createProductCategory();
    $product = createManagedProduct($category);

    $this->getJson(ADMIN_PRODUCTS_ENDPOINT)
        ->assertUnauthorized();

    $this->postJson(ADMIN_PRODUCTS_ENDPOINT, [])
        ->assertUnauthorized();

    $this->getJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid)
        ->assertUnauthorized();

    $this->putJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid, [
        'name' => 'Updated Shirt',
    ])->assertUnauthorized();

    $this->deleteJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid)
        ->assertUnauthorized();
});

it('rejects customers from all admin product endpoints', function () {
    $customer = createProductCustomer();
    $category = createProductCategory();
    $product = createManagedProduct($category);

    $this->actingAs($customer, 'sanctum');

    $this->getJson(ADMIN_PRODUCTS_ENDPOINT)
        ->assertForbidden();

    $this->postJson(ADMIN_PRODUCTS_ENDPOINT, [])
        ->assertForbidden();

    $this->getJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid)
        ->assertForbidden();

    $this->putJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid, [
        'name' => 'Updated Shirt',
    ])->assertForbidden();

    $this->deleteJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid)
        ->assertForbidden();
});

it('allows admins to retrieve all products including inactive products', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();

    createManagedProduct($category);

    createManagedProduct(
        createProductCategory([
            'name' => 'Bottoms',
            'slug' => 'bottoms',
        ]),
        [
            'name' => 'Inactive Pants',
            'slug' => 'inactive-pants',
            'is_active' => false,
        ]
    );

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_PRODUCTS_ENDPOINT)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});

it('allows admins to retrieve a product by uuid', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();
    $product = createManagedProduct($category);

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid)
        ->assertOk()
        ->assertJsonPath('data.uuid', $product->uuid)
        ->assertJsonPath('data.name', 'Essential Shirt')
        ->assertJsonPath('data.category.name', 'Tops');
});

it('allows admins to create a product with an automatically generated slug', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();

    $this->actingAs($admin, 'sanctum')
        ->postJson(ADMIN_PRODUCTS_ENDPOINT, [
            'category_uuid' => $category->uuid,
            'name' => 'New Essential Shirt',
            'description' => 'Produk terbaru.',
            'price' => 350000,
            'discount_price' => 299000,
            'weight_grams' => 300,
            'length_cm' => 30,
            'width_cm' => 25,
            'height_cm' => 3,
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'New Essential Shirt')
        ->assertJsonPath('data.slug', 'new-essential-shirt')
        ->assertJsonPath('data.is_on_sale', true);

    $this->assertDatabaseHas('products', [
        'category_id' => $category->id,
        'name' => 'New Essential Shirt',
        'slug' => 'new-essential-shirt',
        'price' => 350000,
        'discount_price' => 299000,
    ]);
});

it('generates a unique slug when the requested product slug already exists', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();

    createManagedProduct($category, [
        'slug' => 'essential-shirt',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson(ADMIN_PRODUCTS_ENDPOINT, [
            'category_uuid' => $category->uuid,
            'name' => 'Another Shirt',
            'slug' => 'essential-shirt',
            'price' => 250000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'essential-shirt-1');
});

it('validates required product fields and invalid prices', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();

    $this->actingAs($admin, 'sanctum');

    $this->postJson(ADMIN_PRODUCTS_ENDPOINT, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'category_uuid',
            'name',
            'price',
        ]);

    $this->postJson(ADMIN_PRODUCTS_ENDPOINT, [
        'category_uuid' => $category->uuid,
        'name' => 'Invalid Discount Product',
        'price' => 350000,
        'discount_price' => 350000,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['discount_price']);

    $this->postJson(ADMIN_PRODUCTS_ENDPOINT, [
        'category_uuid' => $category->uuid,
        'name' => 'Invalid Price Product',
        'price' => 0,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['price']);
});

it('rejects creating a product under an inactive category', function () {
    $admin = createProductAdmin();

    $category = createProductCategory([
        'is_active' => false,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson(ADMIN_PRODUCTS_ENDPOINT, [
            'category_uuid' => $category->uuid,
            'name' => 'New Shirt',
            'price' => 350000,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_uuid']);
});

it('allows admins to update a product and regenerate its slug when the name changes', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();
    $product = createManagedProduct($category);

    $this->actingAs($admin, 'sanctum')
        ->putJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid, [
            'name' => 'Updated Essential Shirt',
            'price' => 400000,
            'discount_price' => 350000,
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Essential Shirt')
        ->assertJsonPath('data.slug', 'updated-essential-shirt')
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Updated Essential Shirt',
        'slug' => 'updated-essential-shirt',
        'price' => 400000,
        'discount_price' => 350000,
        'is_active' => false,
    ]);
});

it('rejects an update when discount price is not lower than regular price', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();
    $product = createManagedProduct($category);

    $this->actingAs($admin, 'sanctum')
        ->putJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid, [
            'price' => 300000,
            'discount_price' => 350000,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['discount_price']);
});

it('allows admins to delete a product without stock or inventory history', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();
    $product = createManagedProduct($category);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid)
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('products', [
        'id' => $product->id,
    ]);
});

it('prevents admins from deleting a product that still has stock', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();
    $product = createManagedProduct($category);

    createProductVariant($product, [
        'stock_on_hand' => 10,
        'stock_reserved' => 0,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product']);

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
    ]);
});

it('prevents admins from deleting a product with inventory movement history', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();
    $product = createManagedProduct($category);
    $variant = createProductVariant($product);
    $warehouse = createProductWarehouse();

    InventoryMovement::create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'movement_type' => 'stock_in',
        'quantity' => 10,
        'stock_on_hand_before' => 0,
        'stock_on_hand_after' => 10,
        'stock_reserved_before' => 0,
        'stock_reserved_after' => 0,
        'notes' => 'Initial stock.',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product']);

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
    ]);
});

it('returns not found when an admin requests an unknown product uuid', function () {
    $admin = createProductAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_PRODUCTS_ENDPOINT.'/'.fake()->uuid())
        ->assertNotFound();
});

it('prevents admins from deleting a product with inventory reservations', function () {
    $admin = createProductAdmin();
    $category = createProductCategory();
    $product = createManagedProduct($category);

    $variant = createProductVariant($product, [
        'stock_on_hand' => 0,
        'stock_reserved' => 0,
    ]);

    $warehouse = createProductWarehouse();

    $order = Order::create([
        'order_number' => 'ORD-RESERVATION-001',
        'user_id' => createProductCustomer()->id,
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

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('product');

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
    ]);
});

it('preserves order item snapshots when an admin deletes a product', function () {
    $admin = createProductAdmin();
    $customer = createProductCustomer();
    $category = createProductCategory();
    $product = createManagedProduct($category);

    $variant = createProductVariant($product, [
        'stock_on_hand' => 0,
        'stock_reserved' => 0,
    ]);

    $warehouse = createProductWarehouse();

    $order = Order::create([
        'order_number' => 'ORD-SNAPSHOT-001',
        'user_id' => $customer->id,
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

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(ADMIN_PRODUCTS_ENDPOINT.'/'.$product->uuid)
        ->assertOk();

    $this->assertDatabaseMissing('products', [
        'id' => $product->id,
    ]);

    $this->assertDatabaseHas('order_items', [
        'id' => $orderItem->id,
        'product_variant_id' => null,
        'product_name' => $product->name,
        'product_slug' => $product->slug,
        'sku' => $variant->sku,
        'unit_price' => 350000,
        'quantity' => 1,
    ]);
});
