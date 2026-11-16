<?php

use App\Support\ApiResponse;

it('returns a standardized success response', function () {
    $response = ApiResponse::success(
        data: ['id' => 1],
        message: 'Data retrieved successfully.',
    );

    expect($response->getStatusCode())->toBe(200);

    expect($response->getData(true))->toBe([
        'success' => true,
        'message' => 'Data retrieved successfully.',
        'data' => ['id' => 1],
        'meta' => null,
    ]);
});

it('returns a standardized error response', function () {
    $response = ApiResponse::error(
        message: 'Validation failed.',
        status: 422,
        errors: [
            'email' => ['The email field is required.'],
        ],
    );

    expect($response->getStatusCode())->toBe(422);

    expect($response->getData(true))->toBe([
        'success' => false,
        'message' => 'Validation failed.',
        'data' => null,
        'errors' => [
            'email' => ['The email field is required.'],
        ],
    ]);
});

it('supports custom success status codes and metadata', function () {
    $response = ApiResponse::success(
        data: ['id' => 1],
        message: 'Resource created.',
        status: 201,
        meta: ['version' => '1.0.0'],
    );

    expect($response->getStatusCode())->toBe(201);

    expect($response->getData(true)['meta'])->toBe([
        'version' => '1.0.0',
    ]);
});
