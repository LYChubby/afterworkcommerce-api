<?php

use App\Http\Controllers\Api\MidtransNotificationController;
use App\Http\Controllers\Api\PaymentController;
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
