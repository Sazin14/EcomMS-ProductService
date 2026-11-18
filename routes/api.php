<?php

use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductSetupController;
use App\Http\Controllers\Internal\VariantLookupController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Middleware\VerifyServiceKey;
use Illuminate\Support\Facades\Route;

Route::get('categories', [CatalogController::class, 'categories']);
Route::get('categories/tree', [CatalogController::class, 'categoryTree']);
Route::get('products', [CatalogController::class, 'products']);
Route::get('products/filters', [CatalogController::class, 'filters']);
Route::get('products/{slug}', [CatalogController::class, 'show']);

Route::middleware(['auth.jwt', 'authz'])->prefix('admin')->group(function () {
    Route::get('categories', [CategoryController::class, 'index'])->middleware('permission:category.view');
    Route::post('categories', [CategoryController::class, 'store'])->middleware('permission:category.create');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('permission:category.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:category.delete');

    Route::get('categories/{category}/attributes', [ProductSetupController::class, 'categoryAttributes'])->middleware('permission:product.create,category.view');
    Route::post('categories/{category}/attributes', [CategoryController::class, 'attachAttribute'])->middleware('permission:category.update');
    Route::delete('categories/{category}/attributes/{attribute}', [CategoryController::class, 'detachAttribute'])->middleware('permission:category.update');

    Route::get('attributes', [AttributeController::class, 'index'])->middleware('permission:attribute.view');
    Route::post('attributes', [AttributeController::class, 'store'])->middleware('permission:attribute.create');
    Route::put('attributes/{attribute}', [AttributeController::class, 'update'])->middleware('permission:attribute.update');
    Route::delete('attributes/{attribute}', [AttributeController::class, 'destroy'])->middleware('permission:attribute.delete');

    Route::post('attributes/{attribute}/values', [ProductSetupController::class, 'addValue'])->middleware('permission:product.create,attribute.update');
    Route::put('attribute-values/{attributeValue}', [AttributeController::class, 'updateValue'])->middleware('permission:attribute.update');
    Route::delete('attribute-values/{attributeValue}', [AttributeController::class, 'destroyValue'])->middleware('permission:attribute.update');

    Route::get('brands', [ProductSetupController::class, 'brands'])->middleware('permission:product.create,brand.view');
    Route::post('brands', [BrandController::class, 'store'])->middleware('permission:brand.create');
    Route::put('brands/{brand}', [BrandController::class, 'update'])->middleware('permission:brand.update');
    Route::delete('brands/{brand}', [BrandController::class, 'destroy'])->middleware('permission:brand.delete');

    Route::post('products/combinations', [ProductController::class, 'combinations'])->middleware('permission:product.create');

    Route::get('products', [ProductController::class, 'index'])->middleware('permission:product.view');
    Route::get('products/{product}', [ProductController::class, 'show'])->middleware('permission:product.view');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:product.create');
    Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:product.update');
    Route::put('products/{product}/specs', [ProductController::class, 'setSpecs'])->middleware('permission:product.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:product.delete');

    Route::post('products/{product}/variants', [ProductController::class, 'addVariants'])->middleware('permission:product.create');
    Route::put('variants/{variant}', [ProductController::class, 'updateVariant'])->middleware('permission:product.update');
    Route::delete('variants/{variant}', [ProductController::class, 'destroyVariant'])->middleware('permission:product.update');

    Route::post('products/{product}/images', [ProductController::class, 'addImages'])->middleware('permission:product.update');
    Route::delete('images/{image}', [ProductController::class, 'destroyImage'])->middleware('permission:product.update');
});

Route::post('internal/variants/lookup', [VariantLookupController::class, 'lookup'])
    ->middleware(VerifyServiceKey::class);
