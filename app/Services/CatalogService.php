<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\SqlCompat;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CatalogService
{
    public function products(array $f): LengthAwarePaginator
    {
        $categoryIds = isset($f['category_id'])
            ? Category::subtreeIds(Category::where('is_active', true)->findOrFail($f['category_id'])->id, onlyActive: true)
            : null;

        [$variantFilters, $specFilters] = $this->splitAttributeFilters($f['attributes'] ?? []);

        $activeVariant = fn ($q) => $q->where('status', 'active');

        $query = Product::query()->active()
            ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $categoryIds))
            ->when($f['brand_ids'] ?? null, fn ($q, $ids) => $q->whereIn('brand_id', (array) $ids))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('name', SqlCompat::like(), '%'.trim($s).'%'))
            ->whereHas('variants', function ($variant) use ($f, $variantFilters) {
                $variant->where('status', 'active');

                if (isset($f['min_price'])) {
                    $variant->where('selling_price', '>=', $f['min_price']);
                }

                if (isset($f['max_price'])) {
                    $variant->where('selling_price', '<=', $f['max_price']);
                }

                foreach ($variantFilters as $attributeId => $valueIds) {
                    $variant->whereHas('attributeValues', fn ($q) => $q
                        ->where('attribute_values.attribute_id', $attributeId)
                        ->whereIn('attribute_values.id', $valueIds));
                }
            })
            ->with(['brand:id,name,slug', 'thumbnail'])
            ->withMin(['variants as min_price' => $activeVariant], 'selling_price')
            ->withMax(['variants as max_price' => $activeVariant], 'selling_price');

        foreach ($specFilters as $attributeId => $valueIds) {
            $query->whereHas('attributeValues', fn ($q) => $q
                ->where('attribute_values.attribute_id', $attributeId)
                ->whereIn('attribute_values.id', $valueIds));
        }

        match ($f['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('min_price'),
            'price_desc' => $query->orderByDesc('min_price'),
            default => $query->latest(),
        };

        return $query->paginate(min((int) ($f['per_page'] ?? 20), 100));
    }

    public function facets(int $categoryId): array
    {
        $ids = Category::subtreeIds(Category::where('is_active', true)->findOrFail($categoryId)->id, onlyActive: true);

        $inCategory = fn ($q) => $q->active()
            ->whereIn('category_id', $ids)
            ->whereHas('variants', fn ($v) => $v->where('status', 'active'));

        $brands = Brand::query()
            ->where('is_active', true)
            ->whereHas('products', $inCategory)
            ->withCount(['products as products_count' => $inCategory])
            ->orderBy('name')
            ->get();

        $variantRows = $this->variantBase($ids)
            ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'v.id')
            ->join('attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->groupBy('a.id', 'a.name', 'a.slug', 'av.id', 'av.value', 'av.slug', 'av.sort_order')
            ->selectRaw('a.id as attribute_id, a.name as attribute_name, a.slug as attribute_slug,
                         av.id as value_id, av.value, av.slug as value_slug, av.sort_order,
                         count(distinct p.id) as products_count')
            ->get()
            ->map(function ($row) {
                $row->is_variant = true;

                return $row;
            });

        $specRows = DB::table('products as p')
            ->join('product_attributes as pa', fn ($j) => $j->on('pa.product_id', '=', 'p.id')->where('pa.is_variant', false))
            ->join('product_attribute_assignments as paa', 'paa.product_id', '=', 'p.id')
            ->join('attribute_values as av', fn ($j) => $j->on('av.id', '=', 'paa.attribute_value_id')->on('av.attribute_id', '=', 'pa.attribute_id'))
            ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->whereIn('p.category_id', $ids)
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('product_variants as v')
                ->whereColumn('v.product_id', 'p.id')->where('v.status', 'active'))
            ->groupBy('a.id', 'a.name', 'a.slug', 'av.id', 'av.value', 'av.slug', 'av.sort_order')
            ->selectRaw('a.id as attribute_id, a.name as attribute_name, a.slug as attribute_slug,
                         av.id as value_id, av.value, av.slug as value_slug, av.sort_order,
                         count(distinct p.id) as products_count')
            ->get()
            ->map(function ($row) {
                $row->is_variant = false;

                return $row;
            });

        $attributes = $variantRows->concat($specRows)
            ->sortBy([['attribute_name', 'asc'], ['sort_order', 'asc'], ['value', 'asc']])
            ->groupBy('attribute_id')
            ->map(fn ($group) => [
                'id' => $group->first()->attribute_id,
                'name' => $group->first()->attribute_name,
                'slug' => $group->first()->attribute_slug,
                'is_variant' => $group->first()->is_variant,
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

    public function show(string $slug): Product
    {
        $product = Product::active()
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

        $product->variants->each->makeHidden(['purchase_price', 'stock']);
        $product->setAttribute('specs', $product->specGroups());

        return $product;
    }

    /** @return array{0: array<int, int[]>, 1: array<int, int[]>} */
    private function splitAttributeFilters(array $attributes): array
    {
        $clean = [];

        foreach ($attributes as $attributeId => $valueIds) {
            $valueIds = array_values(array_filter(array_map('intval', (array) $valueIds)));

            if ($valueIds !== []) {
                $clean[(int) $attributeId] = $valueIds;
            }
        }

        if ($clean === []) {
            return [[], []];
        }

        $specIds = DB::table('category_attributes')
            ->where('is_variant', false)
            ->whereIn('attribute_id', array_keys($clean))
            ->pluck('attribute_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            array_diff_key($clean, array_flip($specIds)),
            array_intersect_key($clean, array_flip($specIds)),
        ];
    }

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
