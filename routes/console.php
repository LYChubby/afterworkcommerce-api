<?php

use App\Jobs\ExpirePendingOrderJob;
use App\Models\Order;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {

    Order::query()
        ->where('status', 'pending')
        ->where('payment_status', 'pending')
        ->whereNotNull('payment_deadline_at')
        ->where('payment_deadline_at', '<=', now())
        ->orderBy('id')
        ->chunkById(100, function ($orders): void {

            foreach ($orders as $order) {
                ExpirePendingOrderJob::dispatch($order->id);
            }
        });
})
    ->name('expire-pending-orders')
    ->everyMinute()
    ->withoutOverlapping();
