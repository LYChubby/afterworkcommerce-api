<?php

use App\Jobs\CreateBiteshipShipmentJob;
use App\Services\BiteshipOrderService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use RuntimeException;

it('has the expected retry and timeout configuration', function () {
    $job = new CreateBiteshipShipmentJob(123);

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([30, 60, 120, 300])
        ->and($job->timeout)->toBe(60)
        ->and($job->failOnTimeout)->toBeTrue();
});

it('uses overlapping protection for the order', function () {
    $job = new CreateBiteshipShipmentJob(123);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('creates a shipment through the service', function () {
    $service = Mockery::mock(BiteshipOrderService::class);

    $service->shouldReceive('createForOrder')
        ->once()
        ->with(123);

    $job = new CreateBiteshipShipmentJob(123);

    $job->handle($service);
});

it('logs permanent job failure without exposing provider response details', function () {
    Log::shouldReceive('error')
        ->once()
        ->with(
            'Biteship shipment creation job failed permanently.',
            [
                'order_id' => 123,
                'exception' => RuntimeException::class,
            ]
        );

    $job = new CreateBiteshipShipmentJob(123);

    $job->failed(new RuntimeException('Provider request failed'));
});
