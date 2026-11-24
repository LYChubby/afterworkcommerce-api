<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminColorController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AdminProductVariantController;
use App\Http\Controllers\Api\AdminSizeController;
use App\Http\Controllers\Api\AuthenticatedUserController;
use App\Http\Controllers\Api\BiteshipWebhookController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\MidtransNotificationController;
use App\Http\Controllers\Api\OrderShipmentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\Api\ShippingRateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public / Guest Routes
|--------------------------------------------------------------------------
*/

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/register', RegisterController::class)->name('auth.register');
    Route::post('/auth/login', LoginController::class)->name('auth.login');
    Route::post('/auth/forgot-password', ForgotPasswordController::class)
        ->name('auth.password.email');
    Route::post('/auth/reset-password', ResetPasswordController::class)
        ->name('auth.password.update');
});

Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange'])
    ->middleware('throttle:10,1')
    ->name('auth.google.exchange');

/*
|--------------------------------------------------------------------------
| Product Catalog Routes (Public)
|--------------------------------------------------------------------------
*/

Route::get('/products', [ProductController::class, 'index'])
    ->name('products.index');

Route::get('/products/{slug}', [ProductController::class, 'show'])
    ->name('products.show');

Route::get('/categories', [CategoryController::class, 'index'])
    ->name('categories.index');

/*
|--------------------------------------------------------------------------
| Authenticated Routes (Sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/auth/me', [AuthenticatedUserController::class, 'show'])->name('auth.me');
    Route::put('/auth/me', [AuthenticatedUserController::class, 'update'])
        ->name('auth.me.update');
    Route::post('/auth/logout', [AuthenticatedUserController::class, 'destroy'])->name('auth.logout');

    // Orders
    Route::post('/orders/{order}/payment', [PaymentController::class, 'store'])
        ->name('orders.payment.store');

    Route::get('/orders/{order}/shipment', [OrderShipmentController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('orders.shipment.show');

    // Shipping
    Route::post('/shipping/rates', ShippingRateController::class)
        ->middleware('throttle:10,1')
        ->name('shipping.rates');

    // Customer Addresses
    Route::apiResource('addresses', AddressController::class)
        ->parameters(['addresses' => 'uuid']);

    /*
    |--------------------------------------------------------------------------
    | Admin Routes
    |--------------------------------------------------------------------------
    */

    Route::prefix('admin')
        ->middleware('admin')
        ->group(function () {
            Route::apiResource('categories', AdminCategoryController::class)
                ->parameters(['categories' => 'uuid']);

            Route::apiResource('products', AdminProductController::class)
                ->parameters(['products' => 'uuid']);

            Route::apiResource(
                'products.variants',
                AdminProductVariantController::class
            )->parameters([
                'products' => 'product',
                'variants' => 'variant',
            ]);

            Route::apiResource('sizes', AdminSizeController::class)
                ->parameters(['sizes' => 'uuid']);

            Route::apiResource('colors', AdminColorController::class)
                ->parameters(['colors' => 'uuid']);
        });
});

/*
|--------------------------------------------------------------------------
| Webhook / Notification Routes (No Auth)
|--------------------------------------------------------------------------
*/

Route::post('/payments/midtrans/notification', MidtransNotificationController::class)
    ->name('payments.midtrans.notification');

Route::post('/webhooks/biteship', BiteshipWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.biteship');
