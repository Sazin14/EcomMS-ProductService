<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Events ProductService publishes about its catalog. Each one carries the FULL current state of a variant,
 * so a consumer can simply "save what I am told" and repeats or late deliveries do no harm.
 * Always call these inside the same DB::transaction as the change itself.
 */
class CatalogEvents
{
    /** catalog.variant.upserted for every variant of a product (e.g. the product was renamed). */
    public static function productUpserted(Product $product): void
    {
        foreach ($product->variants()->with('attributeValues')->get() as $variant) {
            self::variantUpserted($variant, $product);
        }
    }

    public static function variantUpserted(ProductVariant $variant, ?Product $product = null): void
    {
        $product ??= Product::withTrashed()->findOrFail($variant->product_id);
        $variant->loadMissing('attributeValues');

        Outbox::record('catalog.variant.upserted', [
            'variant_id' => $variant->id,
            'product_id' => $product->id,
            'sku' => $variant->sku,
            'product_name' => $product->name,
            'variant_label' => $variant->attributeValues->sortBy('attribute_id')->pluck('value')->implode(' / ') ?: null,
            'allow_preorder' => (bool) $variant->allow_preorder,
        ]);
    }

    public static function variantDeleted(int $variantId, string $sku): void
    {
        Outbox::record('catalog.variant.deleted', ['variant_id' => $variantId, 'sku' => $sku]);
    }

    /** catalog.variant.deleted for every variant of a product that is being deleted. */
    public static function productDeleted(Product $product): void
    {
        foreach ($product->variants()->get(['id', 'sku']) as $variant) {
            self::variantDeleted($variant->id, $variant->sku);
        }
    }
}
