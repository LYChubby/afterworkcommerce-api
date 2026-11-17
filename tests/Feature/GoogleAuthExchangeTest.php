<?php

use App\Models\OAuthLoginCode;
use App\Models\User;
use App\Services\OAuthLoginCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exchanges a valid OAuth code through the API', function () {
    $user = User::factory()->create();

    $code = app(OAuthLoginCodeService::class)->create($user);

    $response = $this->postJson('/api/auth/google/exchange', [
        'code' => $code,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Login berhasil.')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.uuid', $user->uuid)
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'access_token',
                'token_type',
                'user' => [
                    'uuid',
                    'name',
                    'email',
                    'role',
                    'avatar',
                ],
            ],
            'meta',
        ]);

    expect($user->tokens()->count())->toBe(1);
});

it('rejects an invalid OAuth code through the API', function () {
    $this->postJson('/api/auth/google/exchange', [
        'code' => 'invalid-code',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Invalid login code.');
});

it('rejects an expired OAuth code through the API', function () {
    $user = User::factory()->create();

    $code = str_repeat('a', 64);

    OAuthLoginCode::create([
        'user_id' => $user->id,
        'code_hash' => hash('sha256', $code),
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson('/api/auth/google/exchange', [
        'code' => $code,
    ])
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath(
            'message',
            'Invalid or expired login code.'
        );

    expect($user->tokens()->count())->toBe(0);
});

it('does not allow an OAuth code to be exchanged twice through the API', function () {
    $user = User::factory()->create();

    $code = app(OAuthLoginCodeService::class)->create($user);

    $firstResponse = $this->postJson('/api/auth/google/exchange', [
        'code' => $code,
    ]);

    $firstResponse->assertOk();

    $secondResponse = $this->postJson('/api/auth/google/exchange', [
        'code' => $code,
    ]);

    $secondResponse
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath(
            'message',
            'Invalid or expired login code.'
        );

    expect($user->tokens()->count())->toBe(1);
});

it('requires a code when exchanging an OAuth login code', function () {
    $this->postJson('/api/auth/google/exchange', [])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Invalid login code.');
});
