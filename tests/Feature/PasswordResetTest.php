<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

it('sends password reset notification for registered email', function () {
    Notification::fake();

    $user = User::factory()->create();

    $response = $this->postJson('/api/auth/forgot-password', [
        'email' => $user->email,
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Jika email terdaftar, instruksi reset password akan dikirim.',
        ]);

    Notification::assertSentTo(
        $user,
        ResetPasswordNotification::class
    );
});

it('returns the same response for an unregistered email', function () {
    Notification::fake();

    $response = $this->postJson('/api/auth/forgot-password', [
        'email' => 'unknown@example.com',
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Jika email terdaftar, instruksi reset password akan dikirim.',
        ]);

    Notification::assertNothingSent();
});

it('rejects forgot password request with invalid email', function () {
    $this->postJson('/api/auth/forgot-password', [
        'email' => 'invalid-email',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('resets password and revokes existing sanctum tokens', function () {
    $user = User::factory()->create([
        'password' => 'OldPassword123!',
    ]);

    $user->createToken('existing-session');

    $token = Password::createToken($user);

    $response = $this->postJson('/api/auth/reset-password', [
        'email' => $user->email,
        'token' => $token,
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Password berhasil direset. Silakan login kembali.',
        ]);

    expect(Hash::check('NewPassword123!', $user->fresh()->password))
        ->toBeTrue();

    expect($user->tokens()->count())->toBe(0);
});

it('rejects an invalid password reset token', function () {
    $user = User::factory()->create([
        'password' => 'OldPassword123!',
    ]);

    $response = $this->postJson('/api/auth/reset-password', [
        'email' => $user->email,
        'token' => 'invalid-reset-token',
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ]);

    $response->assertUnprocessable();

    expect(Hash::check('OldPassword123!', $user->fresh()->password))
        ->toBeTrue();
});

it('rejects reset password with mismatched confirmation', function () {
    $user = User::factory()->create();

    $this->postJson('/api/auth/reset-password', [
        'email' => $user->email,
        'token' => 'some-token',
        'password' => 'NewPassword123!',
        'password_confirmation' => 'DifferentPassword123!',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});
