<?php

use App\Models\OAuthLoginCode;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

uses(RefreshDatabase::class);

function makeGoogleSocialiteUser(
    string $id = 'google-123',
    string $email = 'google@example.com',
    bool $verified = true
): GoogleUser {
    $user = new GoogleUser;

    $user->id = $id;
    $user->name = 'Google Test User';
    $user->email = $email;
    $user->avatar = 'https://example.com/avatar.jpg';

    $user->user = [
        'verified_email' => $verified,
    ];

    return $user;
}

it('redirects users to Google OAuth', function () {
    $provider = Mockery::mock(Provider::class);

    $provider->shouldReceive('redirect')
        ->once()
        ->andReturn(
            redirect()->away('https://accounts.google.com/oauth')
        );

    Socialite::shouldReceive('driver')
        ->with('google')
        ->once()
        ->andReturn($provider);

    $this->get('/auth/google')
        ->assertRedirect('https://accounts.google.com/oauth');
});

it('creates a new user after a valid Google callback', function () {
    config([
        'services.frontend.url' => 'http://localhost:3000',
    ]);

    $provider = Mockery::mock(Provider::class);

    $provider->shouldReceive('user')
        ->once()
        ->andReturn(makeGoogleSocialiteUser());

    Socialite::shouldReceive('driver')
        ->with('google')
        ->once()
        ->andReturn($provider);

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect();

    $user = User::query()
        ->where('email', 'google@example.com')
        ->first();

    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->socialAccounts()->count())->toBe(1)
        ->and(OAuthLoginCode::query()
            ->where('user_id', $user->id)
            ->count())->toBe(1);

    $redirectUrl = $response->headers->get('Location');

    parse_str(
        parse_url($redirectUrl, PHP_URL_QUERY),
        $query
    );

    expect($query['code'] ?? null)->toMatch('/\A[a-f0-9]{64}\z/');

    expect($user->tokens()->count())->toBe(0);
});

it('logs in an existing user with a linked Google account', function () {
    config([
        'services.frontend.url' => 'http://localhost:3000',
    ]);

    $user = User::factory()->create([
        'email' => 'google@example.com',
    ]);

    $user->socialAccounts()->create([
        'provider' => 'google',
        'provider_user_id' => 'google-123',
        'email' => 'google@example.com',
    ]);

    $provider = Mockery::mock(Provider::class);

    $provider->shouldReceive('user')
        ->once()
        ->andReturn(makeGoogleSocialiteUser());

    Socialite::shouldReceive('driver')
        ->with('google')
        ->once()
        ->andReturn($provider);

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect();

    expect(User::count())->toBe(1)
        ->and(SocialAccount::count())->toBe(1)
        ->and(OAuthLoginCode::where('user_id', $user->id)->count())
        ->toBe(1);
});

it('rejects an existing email without an existing Google link', function () {
    config([
        'services.frontend.url' => 'http://localhost:3000',
    ]);

    User::factory()->create([
        'email' => 'google@example.com',
    ]);

    $provider = Mockery::mock(Provider::class);

    $provider->shouldReceive('user')
        ->once()
        ->andReturn(makeGoogleSocialiteUser());

    Socialite::shouldReceive('driver')
        ->with('google')
        ->once()
        ->andReturn($provider);

    $this->get('/auth/google/callback')
        ->assertRedirect(
            'http://localhost:3000/login?error=account_conflict'
        );

    expect(User::count())->toBe(1)
        ->and(SocialAccount::count())->toBe(0)
        ->and(OAuthLoginCode::count())->toBe(0);
});

it('rejects a Google account with an unverified email', function () {
    config([
        'services.frontend.url' => 'http://localhost:3000',
    ]);

    $provider = Mockery::mock(Provider::class);

    $provider->shouldReceive('user')
        ->once()
        ->andReturn(
            makeGoogleSocialiteUser(
                verified: false
            )
        );

    Socialite::shouldReceive('driver')
        ->with('google')
        ->once()
        ->andReturn($provider);

    $this->get('/auth/google/callback')
        ->assertRedirect(
            'http://localhost:3000/login?error=invalid_google_account'
        );

    expect(User::count())->toBe(0)
        ->and(SocialAccount::count())->toBe(0)
        ->and(OAuthLoginCode::count())->toBe(0);
});
