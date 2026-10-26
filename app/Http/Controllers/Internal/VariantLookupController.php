<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * OrderService asks "what are these variants called and what do they cost right now?".
 * The cart and the checkout trust this answer, never a price sent by the browser.
 */
class VariantLookupController extends Controller
{
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'variant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'variant_ids.*' => ['integer', 'min:1'],
        ]);

        $variants = ProductVariant::query()
            ->whereIn('id', $data['variant_ids'])
            ->with(['product.images', 'attributeValues'])
            ->get();

        $rows = $variants->map(function (ProductVariant $variant) {
            $product = $variant->product;   // null when the product was deleted (soft delete)
            $valueIds = $variant->attributeValues->pluck('id')->all();

            return [
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'sku' => $variant->sku,
                'product_name' => $product?->name,
                'variant_label' => $variant->attributeValues->sortBy('attribute_id')->pluck('value')->implode(' / ') ?: null,
                'price' => number_format((float) $variant->selling_price, 2, '.', ''),
                'is_active' => $product !== null && $product->status === 'active' && $variant->status === 'active',
                'image_url' => $this->imageFor($product, $valueIds),
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    /** The picture of this variant's colour if there is one, otherwise the product's main picture. */
    private function imageFor($product, array $valueIds): ?string
    {
        if (! $product) {
            return null;
        }

        $images = $product->images->sortBy('sort_order');

        $own = $images->filter(fn ($i) => $i->attribute_value_id && in_array($i->attribute_value_id, $valueIds, true));
        $general = $images->whereNull('attribute_value_id');

        return ($own->firstWhere('is_primary', true)
            ?? $own->first()
            ?? $general->firstWhere('is_primary', true)
            ?? $general->first()
            ?? $images->first())?->url;
    }
}
