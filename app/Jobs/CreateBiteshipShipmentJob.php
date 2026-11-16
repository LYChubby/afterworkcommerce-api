<?php

namespace App\Jobs;

use App\Services\BiteshipOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateBiteshipShipmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 300];

    public function __construct(
        public int $orderId
    ) {}

    public function handle(BiteshipOrderService $biteshipOrderService): void
    {
        $biteshipOrderService->createForOrder($this->orderId);
    }
}
