<?php

use App\Models\Size;
use App\Models\User;

const ADMIN_SIZES_ENDPOINT = '/api/admin/sizes';

function createSizeAdmin(): User
{
    return User::factory()->create([
        'role' => 'admin',
    ]);
}

function createSizeCustomer(): User
{
    return User::factory()->create([
        'role' => 'customer',
    ]);
}

function createSizeRecord(array $attributes = []): Size
{
    return Size::create(array_merge([
        'name' => 'M',
        'sort_order' => 2,
        'is_active' => true,
    ], $attributes));
}

it('rejects guests from all admin size endpoints', function () {
    $size = createSizeRecord();

    $this->getJson(ADMIN_SIZES_ENDPOINT)
        ->assertUnauthorized();

    $this->postJson(ADMIN_SIZES_ENDPOINT, [
        'name' => 'L',
    ])->assertUnauthorized();

    $this->getJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid)
        ->assertUnauthorized();

    $this->putJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid, [
        'name' => 'XL',
    ])->assertUnauthorized();

    $this->deleteJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid)
        ->assertUnauthorized();
});

it('rejects customers from admin size endpoints', function () {
    $customer = createSizeCustomer();
    $size = createSizeRecord();

    $this->actingAs($customer, 'sanctum');

    $this->getJson(ADMIN_SIZES_ENDPOINT)
        ->assertForbidden();

    $this->postJson(ADMIN_SIZES_ENDPOINT, [
        'name' => 'L',
    ])->assertForbidden();

    $this->getJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid)
        ->assertForbidden();

    $this->putJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid, [
        'name' => 'XL',
    ])->assertForbidden();

    $this->deleteJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid)
        ->assertForbidden();
});

it('allows admins to retrieve all sizes ordered by sort order', function () {
    $admin = createSizeAdmin();

    createSizeRecord([
        'name' => 'L',
        'sort_order' => 3,
    ]);

    createSizeRecord([
        'name' => 'S',
        'sort_order' => 1,
    ]);

    createSizeRecord([
        'name' => 'M',
        'sort_order' => 2,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_SIZES_ENDPOINT)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'S')
        ->assertJsonPath('data.1.name', 'M')
        ->assertJsonPath('data.2.name', 'L');
});

it('allows admins to retrieve a size by uuid', function () {
    $admin = createSizeAdmin();
    $size = createSizeRecord();

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid)
        ->assertOk()
        ->assertJsonPath('data.uuid', $size->uuid)
        ->assertJsonPath('data.name', 'M')
        ->assertJsonPath('data.sort_order', 2)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.product_variants_count', 0);
});

it('allows admins to create a size', function () {
    $admin = createSizeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson(ADMIN_SIZES_ENDPOINT, [
            'name' => 'XL',
            'sort_order' => 4,
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'XL')
        ->assertJsonPath('data.sort_order', 4)
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('sizes', [
        'name' => 'XL',
        'sort_order' => 4,
        'is_active' => true,
    ]);
});

it('validates required fields and duplicate size names', function () {
    $admin = createSizeAdmin();

    createSizeRecord([
        'name' => 'M',
    ]);

    $this->actingAs($admin, 'sanctum');

    $this->postJson(ADMIN_SIZES_ENDPOINT, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson(ADMIN_SIZES_ENDPOINT, [
        'name' => 'M',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('validates size sort order and active status', function () {
    $admin = createSizeAdmin();

    $this->actingAs($admin, 'sanctum');

    $this->postJson(ADMIN_SIZES_ENDPOINT, [
        'name' => 'XL',
        'sort_order' => -1,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort_order']);

    $this->postJson(ADMIN_SIZES_ENDPOINT, [
        'name' => 'XL',
        'is_active' => 'invalid',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_active']);
});

it('allows admins to update a size without changing its name', function () {
    $admin = createSizeAdmin();
    $size = createSizeRecord();

    $this->actingAs($admin, 'sanctum')
        ->putJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid, [
            'name' => 'M',
            'sort_order' => 5,
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.uuid', $size->uuid)
        ->assertJsonPath('data.name', 'M')
        ->assertJsonPath('data.sort_order', 5)
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('sizes', [
        'id' => $size->id,
        'name' => 'M',
        'sort_order' => 5,
        'is_active' => false,
    ]);
});

it('allows admins to update a size name', function () {
    $admin = createSizeAdmin();
    $size = createSizeRecord();

    $this->actingAs($admin, 'sanctum')
        ->putJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid, [
            'name' => 'Medium',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Medium');

    $this->assertDatabaseHas('sizes', [
        'id' => $size->id,
        'name' => 'Medium',
    ]);
});

it('prevents admins from assigning an existing size name', function () {
    $admin = createSizeAdmin();

    createSizeRecord([
        'name' => 'M',
    ]);

    $size = createSizeRecord([
        'name' => 'L',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->putJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid, [
            'name' => 'M',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('allows admins to delete a size that is not used by product variants', function () {
    $admin = createSizeAdmin();
    $size = createSizeRecord();

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(ADMIN_SIZES_ENDPOINT.'/'.$size->uuid)
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('sizes', [
        'id' => $size->id,
    ]);
});

it('returns not found when an admin requests an unknown size uuid', function () {
    $admin = createSizeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_SIZES_ENDPOINT.'/'.fake()->uuid())
        ->assertNotFound();
});
