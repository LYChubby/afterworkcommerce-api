<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers a customer and returns an access token', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test Customer',
        'email' => 'customer@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.name', 'Test Customer')
        ->assertJsonPath('data.user.email', 'customer@example.com')
        ->assertJsonPath('data.user.role', 'customer')
        ->assertJsonPath('data.user.email_verified_at', null)
        ->assertJsonPath('data.token_type', 'Bearer');

    $this->assertDatabaseHas('users', [
        'email' => 'customer@example.com',
        'role' => 'customer',
    ]);

    $user = User::where('email', 'customer@example.com')->firstOrFail();

    expect($user->tokens)->toHaveCount(1)
        ->and($user->password)->not->toBe('StrongPass123!')
        ->and($response->json('data.access_token'))->not->toBeEmpty();
});

it('rejects registration with an existing email', function () {
    User::factory()->create([
        'email' => 'customer@example.com',
    ]);

    $this->postJson('/api/auth/register', [
        'name' => 'Another Customer',
        'email' => 'customer@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('rejects registration with an invalid password', function (string $password) {
    $this->postJson('/api/auth/register', [
        'name' => 'Test Customer',
        'email' => 'customer@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
})->with([
    'too short' => 'Ab1!',
    'without uppercase' => 'strongpass123!',
    'without lowercase' => 'STRONGPASS123!',
    'without number' => 'StrongPassword!',
    'without symbol' => 'StrongPass123',
]);

it('rejects registration when password confirmation does not match', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Test Customer',
        'email' => 'customer@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'DifferentPass123!',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('does not allow users to assign their own role during registration', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test Customer',
        'email' => 'customer@example.com',
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
        'role' => 'admin',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.role', 'customer');

    $this->assertDatabaseHas('users', [
        'email' => 'customer@example.com',
        'role' => 'customer',
    ]);
});
