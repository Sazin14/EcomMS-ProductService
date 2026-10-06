<?php

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Product Routes
|--------------------------------------------------------------------------
*/

Route::get('/products', [
    ProductController::class,
    'index'
]);

Route::get('/products/{product}', [
    ProductController::class,
    'show'
]);

Route::get('/categories/{category}/attributes', [
    ProductController::class,
    'categoryAttributes'
]);


/*
|--------------------------------------------------------------------------
| Staff Product Routes
|--------------------------------------------------------------------------
|
| These will use our JWT + Redis authorization middleware.
|
*/

Route::middleware([
    'auth.jwt',
    'authz'
])->group(function () {

    Route::post('/products', [
        ProductController::class,
        'store'
    ])->middleware('permission:product.create');

    Route::put('/products/{product}', [
        ProductController::class,
        'update'
    ])->middleware('permission:product.update');

    Route::delete('/products/{product}', [
        ProductController::class,
        'destroy'
    ])->middleware('permission:product.delete');

    Route::post('/products/{product}/variants', [
        ProductController::class,
        'storeVariants'
    ])->middleware('permission:product.create');

    Route::post('/products/{product}/images', [
        ProductController::class,
        'storeImages'
    ])->middleware('permission:product.update');
});