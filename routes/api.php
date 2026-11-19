<?php

use App\Http\Controllers\Api\MidtransNotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ShippingRateController;
use App\Http\Controllers\Api\BiteshipWebhookController;
use App\Http\Controllers\Api\OrderShipmentController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\AuthenticatedUserController;
use App\Http\Controllers\Api\RegisterController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post(
        '/orders/{order}/payment',
        [PaymentController::class, 'store']
    )->name('orders.payment.store');

    Route::get(
        '/orders/{order}/shipment',
        [OrderShipmentController::class, 'show']
    )
        ->middleware('throttle:60,1')
        ->name('orders.shipment.show');

    Route::get(
        '/auth/me',
        [AuthenticatedUserController::class, 'show']
    )->name('auth.me');

    Route::post(
        '/auth/logout',
        [AuthenticatedUserController::class, 'destroy']
    )->name('auth.logout');
});

Route::post(
    '/auth/register',
    RegisterController::class
)
    ->middleware('throttle:5,1')
    ->name('auth.register');

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

Route::post(
    '/auth/google/exchange',
    [GoogleAuthController::class, 'exchange']
)
    ->middleware('throttle:10,1')
    ->name('auth.google.exchange');
