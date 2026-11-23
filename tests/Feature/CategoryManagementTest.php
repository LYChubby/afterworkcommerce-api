<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;

const ADMIN_CATEGORIES_ENDPOINT = '/api/admin/categories';

function createAdminUser(): User
{
    return User::factory()->create([
        'role' => 'admin',
    ]);
}

function createCustomerUser(): User
{
    return User::factory()->create([
        'role' => 'customer',
    ]);
}

function createCategory(array $attributes = []): Category
{
    return Category::create(array_merge([
        'name' => 'Tops',
        'slug' => 'tops',
        'description' => 'Kategori atasan.',
        'is_active' => true,
    ], $attributes));
}

function createProductForCategory(Category $category): Product
{
    return Product::create([
        'category_id' => $category->id,
        'name' => 'Essential Shirt',
        'slug' => 'essential-shirt-'.$category->id,
        'description' => 'Essential shirt product.',
        'price' => 350000,
        'discount_price' => null,
        'is_active' => true,
    ]);
}

it('rejects guests from all admin category endpoints', function () {
    $category = createCategory();

    $this->getJson(ADMIN_CATEGORIES_ENDPOINT)
        ->assertUnauthorized();

    $this->postJson(ADMIN_CATEGORIES_ENDPOINT, [
        'name' => 'Bottoms',
    ])->assertUnauthorized();

    $this->getJson(ADMIN_CATEGORIES_ENDPOINT.'/'.$category->uuid)
        ->assertUnauthorized();

    $this->putJson(ADMIN_CATEGORIES_ENDPOINT.'/'.$category->uuid, [
        'name' => 'Updated Tops',
    ])->assertUnauthorized();

    $this->deleteJson(ADMIN_CATEGORIES_ENDPOINT.'/'.$category->uuid)
        ->assertUnauthorized();
});

it('rejects customers from admin category endpoints', function () {
    $customer = createCustomerUser();
    $category = createCategory();

    $this->actingAs($customer, 'sanctum');

    $this->getJson(ADMIN_CATEGORIES_ENDPOINT)
        ->assertForbidden();

    $this->postJson(ADMIN_CATEGORIES_ENDPOINT, [
        'name' => 'Bottoms',
    ])->assertForbidden();

    $this->getJson(ADMIN_CATEGORIES_ENDPOINT.'/'.$category->uuid)
        ->assertForbidden();

    $this->putJson(ADMIN_CATEGORIES_ENDPOINT.'/'.$category->uuid, [
        'name' => 'Updated Tops',
    ])->assertForbidden();

    $this->deleteJson(ADMIN_CATEGORIES_ENDPOINT.'/'.$category->uuid)
        ->assertForbidden();
});

it('allows admins to retrieve all categories', function () {
    $admin = createAdminUser();

    createCategory();

    createCategory([
        'name' => 'Bottoms',
        'slug' => 'bottoms',
        'is_active' => false,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_CATEGORIES_ENDPOINT)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});

it('allows admins to retrieve a category by uuid', function () {
    $admin = createAdminUser();
    $category = createCategory();

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_CATEGORIES_ENDPOINT.'/'.$category->uuid)
        ->assertOk()
        ->assertJsonPath('data.uuid', $category->uuid)
        ->assertJsonPath('data.name', 'Tops')
        ->assertJsonPath('data.products_count', 0);
});

it('allows admins to create a category with an automatically generated slug', function () {
    $admin = createAdminUser();

    $this->actingAs($admin, 'sanctum')
        ->postJson(ADMIN_CATEGORIES_ENDPOINT, [
            'name' => 'New Arrivals',
            'description' => 'Kategori produk terbaru.',
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'New Arrivals')
        ->assertJsonPath('data.slug', 'new-arrivals');

    $this->assertDatabaseHas('categories', [
        'name' => 'New Arrivals',
        'slug' => 'new-arrivals',
    ]);
});

it('generates a unique slug when the requested slug already exists', function () {
    $admin = createAdminUser();

    createCategory([
        'name' => 'Tops',
        'slug' => 'tops',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson(ADMIN_CATEGORIES_ENDPOINT, [
            'name' => 'Tops',
        ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'tops-1');
});

it('validates required category fields and duplicate slugs', function () {
    $admin = createAdminUser();

    createCategory([
        'name' => 'Tops',
        'slug' => 'tops',
    ]);

    $this->actingAs($admin, 'sanctum');

    $this->postJson(ADMIN_CATEGORIES_ENDPOINT, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson(ADMIN_CATEGORIES_ENDPOINT, [
        'name' => 'Another Category',
        'slug' => 'tops',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slug']);
});

it('allows admins to update a category and regenerate its slug when the name changes', function () {
    $admin = createAdminUser();
    $category = createCategory();

    $this->actingAs($admin, 'sanctum')
        ->putJson(ADMIN_CATEGORIES_ENDPOINT.'/'.$category->uuid, [
            'name' => 'New Tops',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Tops')
        ->assertJsonPath('data.slug', 'new-tops')
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'New Tops',
        'slug' => 'new-tops',
        'is_active' => false,
    ]);
});

it('allows admins to delete a category without products', function () {
    $admin = createAdminUser();
    $category = createCategory();

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(ADMIN_CATEGORIES_ENDPOINT.'/'.$category->uuid)
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('categories', [
        'id' => $category->id,
    ]);
});

it('prevents admins from deleting a category that still has products', function () {
    $admin = createAdminUser();
    $category = createCategory();

    createProductForCategory($category);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(ADMIN_CATEGORIES_ENDPOINT.'/'.$category->uuid)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category']);

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
    ]);
});

it('returns not found when an admin requests an unknown category uuid', function () {
    $admin = createAdminUser();

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_CATEGORIES_ENDPOINT.'/'.fake()->uuid())
        ->assertNotFound();
});

it('keeps the public category endpoint limited to active categories with active products', function () {
    $activeCategory = createCategory();

    createProductForCategory($activeCategory);

    $inactiveCategory = createCategory([
        'name' => 'Inactive Category',
        'slug' => 'inactive-category',
        'is_active' => false,
    ]);

    $categoryWithoutProducts = createCategory([
        'name' => 'Empty Category',
        'slug' => 'empty-category',
    ]);

    $inactiveProductCategory = createCategory([
        'name' => 'Inactive Product Category',
        'slug' => 'inactive-product-category',
    ]);

    $inactiveProduct = createProductForCategory($inactiveProductCategory);
    $inactiveProduct->update(['is_active' => false]);

    $this->getJson('/api/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $activeCategory->uuid);
});
