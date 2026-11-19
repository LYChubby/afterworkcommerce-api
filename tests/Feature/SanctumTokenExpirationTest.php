<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('rejects an expired sanctum access token', function () {
    config(['sanctum.expiration' => 10080]);

    $user = User::factory()->create();

    $token = $user->createToken('expiration-test');

    $token->accessToken->forceFill([
        'created_at' => Carbon::now()->subMinutes(10081),
    ])->save();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

it('accepts a valid sanctum access token', function () {
    config(['sanctum.expiration' => 10080]);

    $user = User::factory()->create();

    $token = $user->createToken('valid-token-test');

    $this->withToken($token->plainTextToken)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.uuid', $user->uuid);
});
