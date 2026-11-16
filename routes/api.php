<?php

use App\Http\Controllers\Api\MidtransNotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ShippingRateController;
use App\Http\Controllers\Api\BiteshipWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post(
        '/orders/{order}/payment',
        [PaymentController::class, 'store']
    )->name('orders.payment.store');
});

Route::post(
    '/payments/midtrans/notification',
    MidtransNotificationController::class
)->name('payments.midtrans.notification');

Route::middleware([
    'auth:sanctum',
    'throttle:10,1',
])->group(function () {
    Route::post(
        '/shipping/rates',
        ShippingRateController::class
    )->name('shipping.rates');
});

Route::post('/webhooks/biteship', BiteshipWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.biteship');
