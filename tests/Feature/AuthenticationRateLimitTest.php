<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('127.0.0.1');
});

it('limits repeated login attempts', function () {
    $credentials = [
        'email' => 'unknown@example.com',
        'password' => 'WrongPassword123!',
    ];

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/auth/login', $credentials)
            ->assertUnauthorized();
    }

    $this->postJson('/api/auth/login', $credentials)
        ->assertTooManyRequests();
});
