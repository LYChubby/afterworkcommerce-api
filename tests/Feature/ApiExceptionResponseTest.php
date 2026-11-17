<?php

it('returns a standardized 401 response for unauthenticated API requests', function () {
    $this->postJson('/api/shipping/rates', [])
        ->assertUnauthorized()
        ->assertExactJson([
            'success' => false,
            'message' => 'Unauthenticated.',
            'data' => null,
            'errors' => null,
        ]);
});

it('returns a standardized 404 response for unknown API endpoints', function () {
    $this->getJson('/api/endpoint-that-does-not-exist')
        ->assertNotFound()
        ->assertExactJson([
            'success' => false,
            'message' => 'Resource not found.',
            'data' => null,
            'errors' => null,
        ]);
});
