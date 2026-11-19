<?php

namespace App\Jobs;

use App\Services\BiteshipOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateBiteshipShipmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 300];

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $orderId
    ) {}

    /**
     * Prevent concurrent shipment creation for the same order.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("biteship-shipment:order:{$this->orderId}"))
                ->releaseAfter(30)
                ->expireAfter(120),
        ];
    }

    public function handle(BiteshipOrderService $biteshipOrderService): void
    {
        $biteshipOrderService->createForOrder($this->orderId);
    }

    /**
     * Log the job after all retry attempts have failed.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Biteship shipment creation job failed permanently.', [
            'order_id' => $this->orderId,
            'exception' => $exception::class,
        ]);
    }
}
