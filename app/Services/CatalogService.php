<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Storefront side: browse a category, filter by brand / attribute values / price. */
class CatalogService
{
    /**
     * Filters:
     *   category_id, brand_ids[], min_price, max_price, search, sort (newest|price_asc|price_desc), per_page
     *   attributes => [attributeId => [valueId, ...]]   e.g. attributes[1][]=2&attributes[1][]=3&attributes[3][]=10
     *
     * Rule: OR inside one attribute (8GB or 16GB), AND between attributes (and price) -
     * and all of them must be true for the SAME variant.
     */
    public function products(array $f): LengthAwarePaginator
    {
        $categoryIds = isset($f['category_id'])
            ? Category::where('is_active', true)->findOrFail($f['category_id'])->descendantIds()
            : null;

        $activeVariant = fn ($q) => $q->where('status', 'active');

        $query = Product::query()->active()
            ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $categoryIds))
            ->when($f['brand_ids'] ?? null, fn ($q, $ids) => $q->whereIn('brand_id', (array) $ids))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('name', 'ilike', '%'.trim($s).'%'))
            ->whereHas('variants', function ($variant) use ($f) {
                $variant->where('status', 'active');

                if (isset($f['min_price'])) {
                    $variant->where('selling_price', '>=', $f['min_price']);
                }

                if (isset($f['max_price'])) {
                    $variant->where('selling_price', '<=', $f['max_price']);
                }

                foreach ($f['attributes'] ?? [] as $attributeId => $valueIds) {
                    $variant->whereHas('attributeValues', fn ($q) => $q
                        ->where('attribute_values.attribute_id', (int) $attributeId)
                        ->whereIn('attribute_values.id', array_map('intval', (array) $valueIds)));
                }
            })
            ->with(['brand:id,name,slug', 'thumbnail'])
            ->withMin(['variants as min_price' => $activeVariant], 'selling_price')
            ->withMax(['variants as max_price' => $activeVariant], 'selling_price');

        match ($f['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('min_price'),
            'price_desc' => $query->orderByDesc('min_price'),
            default => $query->latest(),
        };

        return $query->paginate(min((int) ($f['per_page'] ?? 20), 100));
    }

    /** Sidebar data for a category: brands under it, attribute values that really exist, price range. */
    public function facets(int $categoryId): array
    {
        $ids = Category::where('is_active', true)->findOrFail($categoryId)->descendantIds();

        $inCategory = fn ($q) => $q->active()->whereIn('category_id', $ids);

        $brands = Brand::query()
            ->where('is_active', true)
            ->whereHas('products', $inCategory)
            ->withCount(['products as products_count' => $inCategory])
            ->orderBy('name')
            ->get();

        $rows = $this->variantBase($ids)
            ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'v.id')
            ->join('attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->groupBy('a.id', 'a.name', 'a.slug', 'av.id', 'av.value', 'av.slug', 'av.sort_order')
            ->selectRaw('a.id as attribute_id, a.name as attribute_name, a.slug as attribute_slug,
                         av.id as value_id, av.value, av.slug as value_slug,
                         count(distinct p.id) as products_count')
            ->orderBy('a.name')->orderBy('av.sort_order')->orderBy('av.value')
            ->get();

        $attributes = $rows->groupBy('attribute_id')->map(fn ($group) => [
            'id' => $group->first()->attribute_id,
            'name' => $group->first()->attribute_name,
            'slug' => $group->first()->attribute_slug,
            'values' => $group->map(fn ($r) => [
                'id' => $r->value_id,
                'value' => $r->value,
                'slug' => $r->value_slug,
                'products_count' => $r->products_count,
            ])->values(),
        ])->values();

        $price = $this->variantBase($ids)
            ->selectRaw('min(v.selling_price) as min, max(v.selling_price) as max')
            ->first();

        return [
            'brands' => $brands,
            'attributes' => $attributes,
            'price' => ['min' => $price->min, 'max' => $price->max],
        ];
    }

    /** Product page: active variants with their values, plus images. */
    public function show(string $slug): Product
    {
        return Product::active()
            ->where('slug', $slug)
            ->with([
                'category:id,name,slug',
                'brand:id,name,slug',
                'attributes',
                'attributeValues.attribute',
                'variants' => fn ($q) => $q->where('status', 'active'),
                'variants.attributeValues.attribute',
                'images',
            ])
            ->firstOrFail();
    }

    /** Active variants of active products in the given categories. */
    private function variantBase(array $categoryIds)
    {
        return DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('p.category_id', $categoryIds)
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->where('v.status', 'active');
    }
}
