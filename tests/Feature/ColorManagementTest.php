<?php

use App\Models\Color;
use App\Models\User;

const ADMIN_COLORS_ENDPOINT = '/api/admin/colors';

function createColorAdmin(): User
{
    return User::factory()->create([
        'role' => 'admin',
    ]);
}

function createColorCustomer(): User
{
    return User::factory()->create([
        'role' => 'customer',
    ]);
}

function createColorRecord(array $attributes = []): Color
{
    return Color::create(array_merge([
        'name' => 'Black',
        'hex_code' => '#000000',
        'is_active' => true,
    ], $attributes));
}

it('rejects guests from all admin color endpoints', function () {
    $color = createColorRecord();

    $this->getJson(ADMIN_COLORS_ENDPOINT)
        ->assertUnauthorized();

    $this->postJson(ADMIN_COLORS_ENDPOINT, [
        'name' => 'White',
        'hex_code' => '#FFFFFF',
    ])->assertUnauthorized();

    $this->getJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid)
        ->assertUnauthorized();

    $this->putJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid, [
        'name' => 'Updated Black',
    ])->assertUnauthorized();

    $this->deleteJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid)
        ->assertUnauthorized();
});

it('rejects customers from admin color endpoints', function () {
    $customer = createColorCustomer();
    $color = createColorRecord();

    $this->actingAs($customer, 'sanctum');

    $this->getJson(ADMIN_COLORS_ENDPOINT)
        ->assertForbidden();

    $this->postJson(ADMIN_COLORS_ENDPOINT, [
        'name' => 'White',
        'hex_code' => '#FFFFFF',
    ])->assertForbidden();

    $this->getJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid)
        ->assertForbidden();

    $this->putJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid, [
        'name' => 'Updated Black',
    ])->assertForbidden();

    $this->deleteJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid)
        ->assertForbidden();
});

it('allows admins to retrieve all colors ordered by name', function () {
    $admin = createColorAdmin();

    createColorRecord([
        'name' => 'White',
        'hex_code' => '#FFFFFF',
    ]);

    createColorRecord([
        'name' => 'Blue',
        'hex_code' => '#0000FF',
    ]);

    createColorRecord([
        'name' => 'Black',
        'hex_code' => '#000000',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_COLORS_ENDPOINT)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'Black')
        ->assertJsonPath('data.1.name', 'Blue')
        ->assertJsonPath('data.2.name', 'White');
});

it('allows admins to retrieve a color by uuid', function () {
    $admin = createColorAdmin();
    $color = createColorRecord();

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid)
        ->assertOk()
        ->assertJsonPath('data.uuid', $color->uuid)
        ->assertJsonPath('data.name', 'Black')
        ->assertJsonPath('data.hex_code', '#000000')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.product_variants_count', 0);
});

it('allows admins to create a color', function () {
    $admin = createColorAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson(ADMIN_COLORS_ENDPOINT, [
            'name' => 'Navy',
            'hex_code' => '#000080',
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Navy')
        ->assertJsonPath('data.hex_code', '#000080')
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('colors', [
        'name' => 'Navy',
        'hex_code' => '#000080',
        'is_active' => true,
    ]);
});

it('validates required color fields and duplicate names', function () {
    $admin = createColorAdmin();

    createColorRecord([
        'name' => 'Black',
    ]);

    $this->actingAs($admin, 'sanctum');

    $this->postJson(ADMIN_COLORS_ENDPOINT, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'hex_code']);

    $this->postJson(ADMIN_COLORS_ENDPOINT, [
        'name' => 'Black',
        'hex_code' => '#111111',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('rejects invalid hexadecimal color codes', function () {
    $admin = createColorAdmin();

    $this->actingAs($admin, 'sanctum');

    $this->postJson(ADMIN_COLORS_ENDPOINT, [
        'name' => 'Invalid Color',
        'hex_code' => 'red',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hex_code']);

    $this->postJson(ADMIN_COLORS_ENDPOINT, [
        'name' => 'Invalid Hex',
        'hex_code' => '#12AB',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hex_code']);
});

it('allows admins to update a color', function () {
    $admin = createColorAdmin();
    $color = createColorRecord();

    $this->actingAs($admin, 'sanctum')
        ->putJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid, [
            'name' => 'Charcoal',
            'hex_code' => '#36454F',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.uuid', $color->uuid)
        ->assertJsonPath('data.name', 'Charcoal')
        ->assertJsonPath('data.hex_code', '#36454F')
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('colors', [
        'id' => $color->id,
        'name' => 'Charcoal',
        'hex_code' => '#36454F',
        'is_active' => false,
    ]);
});

it('allows admins to update a color without changing its name', function () {
    $admin = createColorAdmin();
    $color = createColorRecord();

    $this->actingAs($admin, 'sanctum')
        ->putJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid, [
            'name' => 'Black',
            'hex_code' => '#111111',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Black')
        ->assertJsonPath('data.hex_code', '#111111');
});

it('prevents admins from assigning an existing color name', function () {
    $admin = createColorAdmin();

    createColorRecord([
        'name' => 'Black',
    ]);

    $color = createColorRecord([
        'name' => 'White',
        'hex_code' => '#FFFFFF',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->putJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid, [
            'name' => 'Black',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('allows admins to delete a color that is not used by product variants', function () {
    $admin = createColorAdmin();
    $color = createColorRecord();

    $this->actingAs($admin, 'sanctum')
        ->deleteJson(ADMIN_COLORS_ENDPOINT.'/'.$color->uuid)
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('colors', [
        'id' => $color->id,
    ]);
});

it('returns not found when an admin requests an unknown color uuid', function () {
    $admin = createColorAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson(ADMIN_COLORS_ENDPOINT.'/'.fake()->uuid())
        ->assertNotFound();
});
