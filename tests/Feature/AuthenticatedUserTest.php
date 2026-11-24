<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

it('returns the authenticated user profile', function () {
    $user = User::factory()->create([
        'role' => 'customer',
    ]);

    $token = $user->createToken('test-token')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath(
            'message',
            'User profile retrieved successfully.'
        )
        ->assertJsonPath('data.uuid', $user->uuid)
        ->assertJsonPath('data.name', $user->name)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.role', $user->role);
});

it('rejects unauthenticated profile requests', function () {
    $this->getJson('/api/auth/me')
        ->assertUnauthorized();
});

it('logs out the authenticated user', function () {
    $user = User::factory()->create();

    $token = $user->createToken('test-token')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/auth/logout')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Logout berhasil.');

    expect($user->tokens()->count())->toBe(0);
});

it('only revokes the current token during logout', function () {
    $user = User::factory()->create();

    $firstToken = $user->createToken('first-token');
    $secondToken = $user->createToken('second-token');

    $this->withToken($firstToken->plainTextToken)
        ->postJson('/api/auth/logout')
        ->assertOk();

    expect($user->tokens()->count())->toBe(1)
        ->and(
            PersonalAccessToken::find($secondToken->accessToken->id)
        )->not->toBeNull();
});

it('rejects requests using a revoked token', function () {
    $user = User::factory()->create();

    $token = $user->createToken('test-token')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/auth/logout')
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);

    // Clear cached authentication guards between requests.
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

it('rejects unauthenticated logout requests', function () {
    $this->postJson('/api/auth/logout')
        ->assertUnauthorized();
});

it('updates the authenticated user profile', function () {
    $user = User::factory()->create([
        'role' => 'customer',
        'name' => 'Old Name',
        'phone' => '081234567890',
    ]);

    $token = $user->createToken('test-token')->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/auth/me', [
            'name' => 'New Name',
            'phone' => '082345678901',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath(
            'message',
            'User profile updated successfully.'
        )
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.phone', '082345678901');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'New Name',
        'phone' => '082345678901',
    ]);
});

it('does not allow customers to update protected profile attributes', function () {
    $user = User::factory()->create([
        'role' => 'customer',
        'email' => 'customer@example.com',
    ]);

    $token = $user->createToken('test-token')->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/auth/me', [
            'name' => 'Updated Name',
            'email' => 'changed@example.com',
            'role' => 'admin',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Name')
        ->assertJsonPath('data.email', 'customer@example.com')
        ->assertJsonPath('data.role', 'customer');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => 'customer@example.com',
        'role' => 'customer',
    ]);
});

it('rejects unauthenticated profile update requests', function () {
    $this->putJson('/api/auth/me', [
        'name' => 'Updated Name',
        'phone' => '081234567890',
    ])->assertUnauthorized();
});

it('validates profile update data', function (array $payload, string $field) {
    $user = User::factory()->create([
        'role' => 'customer',
    ]);

    $token = $user->createToken('test-token')->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/auth/me', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'name is required when provided' => [
        ['name' => ''],
        'name',
    ],
    'name must be a string' => [
        ['name' => 123],
        'name',
    ],
    'name exceeds maximum length' => [
        ['name' => str_repeat('a', 256)],
        'name',
    ],
    'phone must be a string' => [
        ['phone' => 1234567890],
        'phone',
    ],
    'phone exceeds maximum length' => [
        ['phone' => str_repeat('1', 31)],
        'phone',
    ],
]);
