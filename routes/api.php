<?php

use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductSetupController;
use App\Http\Controllers\Storefront\CatalogController;
use Illuminate\Support\Facades\Route;

/*
 * Public storefront
 */
Route::get('categories', [CatalogController::class, 'categories']);
Route::get('products', [CatalogController::class, 'products']);
Route::get('products/filters', [CatalogController::class, 'filters']);   // keep above {slug}
Route::get('products/{slug}', [CatalogController::class, 'show']);

/*
 * Staff (JWT + Redis authorization middleware)
 * Everything lives under /admin so the public GET /products never clashes with the staff list.
 * "product.update" is used for staff reads; add a "product.view" permission later if you want it separate.
 */
Route::middleware(['auth.jwt', 'authz'])->prefix('admin')->group(function () {

    // form helpers
    Route::get('categories/{category}/attributes', [ProductSetupController::class, 'categoryAttributes'])->middleware('permission:product.create');
    Route::post('attributes/{attribute}/values', [ProductSetupController::class, 'addValue'])->middleware('permission:product.create');
    Route::get('brands', [ProductSetupController::class, 'brands'])->middleware('permission:product.create');
    Route::post('products/combinations', [ProductController::class, 'combinations'])->middleware('permission:product.create');

    // products
    Route::get('products', [ProductController::class, 'index'])->middleware('permission:product.update');
    Route::get('products/{product}', [ProductController::class, 'show'])->middleware('permission:product.update');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:product.create');
    Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:product.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:product.delete');

    // variants
    Route::post('products/{product}/variants', [ProductController::class, 'addVariants'])->middleware('permission:product.create');
    Route::put('variants/{variant}', [ProductController::class, 'updateVariant'])->middleware('permission:product.update');
    Route::delete('variants/{variant}', [ProductController::class, 'destroyVariant'])->middleware('permission:product.update');

    // images
    Route::post('products/{product}/images', [ProductController::class, 'addImages'])->middleware('permission:product.update');
    Route::delete('images/{image}', [ProductController::class, 'destroyImage'])->middleware('permission:product.update');
});
