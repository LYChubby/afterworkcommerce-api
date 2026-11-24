<?php

use App\Models\Address;
use App\Models\User;

const CUSTOMER_ADDRESSES_ENDPOINT = '/api/addresses';

function createAddressCustomer(): User
{
    return User::factory()->create([
        'role' => 'customer',
    ]);
}

function createAddressRecord(
    User $user,
    array $attributes = []
): Address {
    return Address::create(array_merge([
        'user_id' => $user->id,
        'label' => 'Rumah',
        'recipient_name' => 'Test Recipient',
        'phone' => '081234567890',
        'address_line' => 'Jl. Merdeka No. 10',
        'province' => 'DI Yogyakarta',
        'city' => 'Yogyakarta',
        'district' => 'Gondokusuman',
        'subdistrict' => 'Baciro',
        'postal_code' => '55225',
        'country' => 'Indonesia',
        'notes' => null,
        'is_default' => false,
    ], $attributes));
}

function validAddressPayload(array $attributes = []): array
{
    return array_merge([
        'label' => 'Rumah',
        'recipient_name' => 'Test Recipient',
        'phone' => '081234567890',
        'address_line' => 'Jl. Merdeka No. 10',
        'province' => 'DI Yogyakarta',
        'city' => 'Yogyakarta',
        'district' => 'Gondokusuman',
        'subdistrict' => 'Baciro',
        'postal_code' => '55225',
        'country' => 'Indonesia',
        'notes' => null,
    ], $attributes);
}

it('rejects guests from all customer address endpoints', function () {
    $user = createAddressCustomer();
    $address = createAddressRecord($user);

    $this->getJson(CUSTOMER_ADDRESSES_ENDPOINT)
        ->assertUnauthorized();

    $this->postJson(
        CUSTOMER_ADDRESSES_ENDPOINT,
        validAddressPayload()
    )->assertUnauthorized();

    $this->getJson(
        CUSTOMER_ADDRESSES_ENDPOINT.'/'.$address->uuid
    )->assertUnauthorized();

    $this->putJson(
        CUSTOMER_ADDRESSES_ENDPOINT.'/'.$address->uuid,
        ['label' => 'Kantor']
    )->assertUnauthorized();

    $this->deleteJson(
        CUSTOMER_ADDRESSES_ENDPOINT.'/'.$address->uuid
    )->assertUnauthorized();
});

it('allows customers to retrieve only their own addresses', function () {
    $customer = createAddressCustomer();
    $otherCustomer = createAddressCustomer();

    createAddressRecord($customer, [
        'label' => 'Rumah',
        'is_default' => true,
    ]);

    createAddressRecord($otherCustomer, [
        'label' => 'Kantor',
        'is_default' => true,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson(CUSTOMER_ADDRESSES_ENDPOINT)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'Rumah');
});

it('allows customers to retrieve an address by uuid', function () {
    $customer = createAddressCustomer();

    $address = createAddressRecord($customer, [
        'is_default' => true,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson(
            CUSTOMER_ADDRESSES_ENDPOINT.'/'.$address->uuid
        )
        ->assertOk()
        ->assertJsonPath('data.uuid', $address->uuid)
        ->assertJsonPath('data.label', 'Rumah')
        ->assertJsonPath('data.recipient_name', 'Test Recipient')
        ->assertJsonPath('data.phone', '081234567890')
        ->assertJsonPath('data.is_default', true);
});

it('automatically makes the first created address the default address', function () {
    $customer = createAddressCustomer();

    $this->actingAs($customer, 'sanctum')
        ->postJson(
            CUSTOMER_ADDRESSES_ENDPOINT,
            validAddressPayload([
                'is_default' => false,
            ])
        )
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_default', true);

    $this->assertDatabaseHas('addresses', [
        'user_id' => $customer->id,
        'is_default' => true,
    ]);
});

it('allows customers to create an additional non-default address', function () {
    $customer = createAddressCustomer();

    $defaultAddress = createAddressRecord($customer, [
        'label' => 'Rumah',
        'is_default' => true,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson(
            CUSTOMER_ADDRESSES_ENDPOINT,
            validAddressPayload([
                'label' => 'Kantor',
            ])
        )
        ->assertCreated()
        ->assertJsonPath('data.label', 'Kantor')
        ->assertJsonPath('data.is_default', false);

    $this->assertDatabaseHas('addresses', [
        'id' => $defaultAddress->id,
        'is_default' => true,
    ]);
});

it('allows customers to create a new default address', function () {
    $customer = createAddressCustomer();

    $oldDefault = createAddressRecord($customer, [
        'label' => 'Rumah',
        'is_default' => true,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson(
            CUSTOMER_ADDRESSES_ENDPOINT,
            validAddressPayload([
                'label' => 'Kantor',
                'is_default' => true,
            ])
        )
        ->assertCreated()
        ->assertJsonPath('data.label', 'Kantor')
        ->assertJsonPath('data.is_default', true);

    $this->assertDatabaseHas('addresses', [
        'id' => $oldDefault->id,
        'is_default' => false,
    ]);

    $this->assertDatabaseCount('addresses', 2);

    $this->assertSame(
        1,
        Address::query()
            ->where('user_id', $customer->id)
            ->where('is_default', true)
            ->count()
    );
});

it('validates required address fields', function () {
    $customer = createAddressCustomer();

    $this->actingAs($customer, 'sanctum')
        ->postJson(CUSTOMER_ADDRESSES_ENDPOINT, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'recipient_name',
            'phone',
            'address_line',
            'province',
            'city',
            'postal_code',
        ]);
});

it('validates address field formats and lengths', function () {
    $customer = createAddressCustomer();

    $this->actingAs($customer, 'sanctum');

    $this->postJson(
        CUSTOMER_ADDRESSES_ENDPOINT,
        validAddressPayload([
            'label' => str_repeat('A', 51),
        ])
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['label']);

    $this->postJson(
        CUSTOMER_ADDRESSES_ENDPOINT,
        validAddressPayload([
            'is_default' => 'invalid',
        ])
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_default']);
});

it('does not allow customers to assign addresses to another user', function () {
    $customer = createAddressCustomer();
    $otherCustomer = createAddressCustomer();

    $this->actingAs($customer, 'sanctum')
        ->postJson(
            CUSTOMER_ADDRESSES_ENDPOINT,
            validAddressPayload([
                'user_id' => $otherCustomer->id,
            ])
        )
        ->assertCreated();

    $this->assertDatabaseHas('addresses', [
        'user_id' => $customer->id,
        'recipient_name' => 'Test Recipient',
    ]);

    $this->assertDatabaseMissing('addresses', [
        'user_id' => $otherCustomer->id,
        'recipient_name' => 'Test Recipient',
    ]);
});

it('allows customers to update their own address', function () {
    $customer = createAddressCustomer();

    $address = createAddressRecord($customer);

    $this->actingAs($customer, 'sanctum')
        ->putJson(
            CUSTOMER_ADDRESSES_ENDPOINT.'/'.$address->uuid,
            [
                'label' => 'Kantor',
                'recipient_name' => 'Updated Recipient',
                'phone' => '081298765432',
            ]
        )
        ->assertOk()
        ->assertJsonPath('data.uuid', $address->uuid)
        ->assertJsonPath('data.label', 'Kantor')
        ->assertJsonPath('data.recipient_name', 'Updated Recipient')
        ->assertJsonPath('data.phone', '081298765432');

    $this->assertDatabaseHas('addresses', [
        'id' => $address->id,
        'label' => 'Kantor',
        'recipient_name' => 'Updated Recipient',
        'phone' => '081298765432',
    ]);
});

it('allows customers to change their default address', function () {
    $customer = createAddressCustomer();

    $oldDefault = createAddressRecord($customer, [
        'label' => 'Rumah',
        'is_default' => true,
    ]);

    $newDefault = createAddressRecord($customer, [
        'label' => 'Kantor',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson(
            CUSTOMER_ADDRESSES_ENDPOINT.'/'.$newDefault->uuid,
            ['is_default' => true]
        )
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    $this->assertDatabaseHas('addresses', [
        'id' => $oldDefault->id,
        'is_default' => false,
    ]);

    $this->assertDatabaseHas('addresses', [
        'id' => $newDefault->id,
        'is_default' => true,
    ]);

    $this->assertSame(
        1,
        Address::query()
            ->where('user_id', $customer->id)
            ->where('is_default', true)
            ->count()
    );
});

it('prevents customers from accessing another users address', function () {
    $customer = createAddressCustomer();
    $otherCustomer = createAddressCustomer();

    $address = createAddressRecord($otherCustomer);

    $this->actingAs($customer, 'sanctum');

    $this->getJson(
        CUSTOMER_ADDRESSES_ENDPOINT.'/'.$address->uuid
    )->assertNotFound();

    $this->putJson(
        CUSTOMER_ADDRESSES_ENDPOINT.'/'.$address->uuid,
        ['label' => 'Manipulated']
    )->assertNotFound();

    $this->deleteJson(
        CUSTOMER_ADDRESSES_ENDPOINT.'/'.$address->uuid
    )->assertNotFound();

    $this->assertDatabaseHas('addresses', [
        'id' => $address->id,
        'label' => 'Rumah',
    ]);
});

it('allows customers to delete their own address', function () {
    $customer = createAddressCustomer();

    $address = createAddressRecord($customer);

    $this->actingAs($customer, 'sanctum')
        ->deleteJson(
            CUSTOMER_ADDRESSES_ENDPOINT.'/'.$address->uuid
        )
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('addresses', [
        'id' => $address->id,
    ]);
});

it('promotes the latest remaining address when the default address is deleted', function () {
    $customer = createAddressCustomer();

    $oldDefault = createAddressRecord($customer, [
        'label' => 'Rumah',
        'is_default' => true,
    ]);

    $replacement = createAddressRecord($customer, [
        'label' => 'Kantor',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->deleteJson(
            CUSTOMER_ADDRESSES_ENDPOINT.'/'.$oldDefault->uuid
        )
        ->assertOk();

    $this->assertDatabaseMissing('addresses', [
        'id' => $oldDefault->id,
    ]);

    $this->assertDatabaseHas('addresses', [
        'id' => $replacement->id,
        'is_default' => true,
    ]);
});

it('returns not found for an unknown address uuid', function () {
    $customer = createAddressCustomer();

    $this->actingAs($customer, 'sanctum')
        ->getJson(
            CUSTOMER_ADDRESSES_ENDPOINT.'/'.fake()->uuid()
        )
        ->assertNotFound();
});

it('promotes another address when the current default address is unset', function () {
    $customer = createAddressCustomer();

    $oldDefault = createAddressRecord($customer, [
        'label' => 'Rumah',
        'is_default' => true,
    ]);

    $replacement = createAddressRecord($customer, [
        'label' => 'Kantor',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson(
            CUSTOMER_ADDRESSES_ENDPOINT.'/'.$oldDefault->uuid,
            ['is_default' => false]
        )
        ->assertOk()
        ->assertJsonPath('data.is_default', false);

    $this->assertDatabaseHas('addresses', [
        'id' => $oldDefault->id,
        'is_default' => false,
    ]);

    $this->assertDatabaseHas('addresses', [
        'id' => $replacement->id,
        'is_default' => true,
    ]);

    $this->assertSame(
        1,
        Address::query()
            ->where('user_id', $customer->id)
            ->where('is_default', true)
            ->count()
    );
});

it('preserves the default status when unsetting the only address', function () {
    $customer = createAddressCustomer();

    $address = createAddressRecord($customer, [
        'is_default' => true,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson(
            CUSTOMER_ADDRESSES_ENDPOINT.'/'.$address->uuid,
            ['is_default' => false]
        )
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    $this->assertDatabaseHas('addresses', [
        'id' => $address->id,
        'is_default' => true,
    ]);
});
