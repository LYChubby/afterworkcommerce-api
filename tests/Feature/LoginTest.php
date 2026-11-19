<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('authenticates a user with valid credentials and returns an access token', function () {
    $user = User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'StrongPass123!',
        'role' => 'customer',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'StrongPass123!',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.uuid', $user->uuid)
        ->assertJsonPath('data.user.email', 'customer@example.com')
        ->assertJsonPath('data.user.role', 'customer')
        ->assertJsonPath('data.token_type', 'Bearer');

    expect($user->fresh()->tokens)->toHaveCount(1)
        ->and($response->json('data.access_token'))->not->toBeEmpty();
});

it('rejects login with an incorrect password', function () {
    User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'StrongPass123!',
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'WrongPass123!',
    ])
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Email atau password tidak valid.')
        ->assertJsonPath('data', null);
});

it('rejects login with an unknown email', function () {
    $this->postJson('/api/auth/login', [
        'email' => 'unknown@example.com',
        'password' => 'StrongPass123!',
    ])
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Email atau password tidak valid.');
});

it('rejects login when required credentials are missing', function () {
    $this->postJson('/api/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'email',
            'password',
        ]);
});

it('creates a new token without revoking existing tokens', function () {
    $user = User::factory()->create([
        'email' => 'customer@example.com',
        'password' => 'StrongPass123!',
    ]);

    $existingToken = $user->createToken('existing-session');

    $this->postJson('/api/auth/login', [
        'email' => 'customer@example.com',
        'password' => 'StrongPass123!',
    ])->assertOk();

    expect($user->fresh()->tokens)->toHaveCount(2);

    $this->assertDatabaseHas('personal_access_tokens', [
        'id' => $existingToken->accessToken->id,
        'name' => 'existing-session',
    ]);
});
