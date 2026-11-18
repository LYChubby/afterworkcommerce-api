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
