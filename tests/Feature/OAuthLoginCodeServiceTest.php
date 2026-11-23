<?php

use App\Models\OAuthLoginCode;
use App\Models\User;
use App\Services\OAuthLoginCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

uses(RefreshDatabase::class);

it('exchanges a valid OAuth login code for a Sanctum token', function () {
    $user = User::factory()->create();

    $service = app(OAuthLoginCodeService::class);

    $code = $service->create($user);

    $result = $service->exchange($code);

    expect($result->plainTextToken)->not->toBeEmpty()
        ->and($result->accessToken->tokenable_id)->toBe($user->id)
        ->and($user->tokens()->count())->toBe(1);

    $loginCode = OAuthLoginCode::query()
        ->where('user_id', $user->id)
        ->first();

    expect($loginCode->consumed_at)->not->toBeNull();
});

it('does not allow an OAuth login code to be used twice', function () {
    $user = User::factory()->create();

    $service = app(OAuthLoginCodeService::class);

    $code = $service->create($user);

    $service->exchange($code);

    expect(fn () => $service->exchange($code))
        ->toThrow(RuntimeException::class);

    expect($user->tokens()->count())->toBe(1);
});

it('rejects an expired OAuth login code', function () {
    $user = User::factory()->create();

    $code = str_repeat('a', 64);

    OAuthLoginCode::create([
        'user_id' => $user->id,
        'code_hash' => hash('sha256', $code),
        'expires_at' => now()->subMinute(),
    ]);

    $service = app(OAuthLoginCodeService::class);

    expect(fn () => $service->exchange($code))
        ->toThrow(RuntimeException::class);

    expect($user->tokens()->count())->toBe(0);
});

it('rejects an invalid OAuth login code', function () {
    $user = User::factory()->create();

    $service = app(OAuthLoginCodeService::class);

    expect(fn () => $service->exchange('invalid-code'))
        ->toThrow(RuntimeException::class);

    expect($user->tokens()->count())->toBe(0);
});

it('does not create an additional token for an already consumed code', function () {
    $user = User::factory()->create();

    $service = app(OAuthLoginCodeService::class);

    $code = $service->create($user);

    $service->exchange($code);

    $tokenCountBefore = $user->tokens()->count();

    expect(fn () => $service->exchange($code))
        ->toThrow(RuntimeException::class);

    expect($user->tokens()->count())->toBe($tokenCountBefore);
});
