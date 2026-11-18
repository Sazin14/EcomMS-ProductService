<?php

namespace App\Services;

use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\CatalogEvents;
use App\Support\SqlCompat;
use App\Services\Concerns\FailsValidation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    use FailsValidation;

    private const DETAILS = [
        'category', 'brand', 'attributes', 'attributeValues.attribute',
        'variants.attributeValues.attribute', 'images',
    ];

    public function __construct(
        private VariantService $variants,
        private ProductImageService $images,
        private AttributeService $attributes,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        return Product::query()
            ->with(['category:id,name', 'brand:id,name', 'thumbnail'])
            ->withCount('variants')
            ->withMin('variants as min_price', 'selling_price')
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->whereIn('category_id', Category::subtreeIds((int) $v)))
            ->when($filters['brand_id'] ?? null, fn ($q, $v) => $q->where('brand_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('name', SqlCompat::like(), '%'.trim($v).'%'))
            ->latest()
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function find(int $id): Product
    {
        $product = Product::with(self::DETAILS)->findOrFail($id);

        $product->setAttribute('specs', $product->specGroups());

        $product->variants->each->makeHidden('stock');

        return $product;
    }

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $category = Category::findOrFail($data['category_id']);

            if (! $category->is_active) {
                $this->fail('category_id', 'The selected category is inactive.');
            }

            $brand = $this->resolveBrand($data['brand_id'] ?? null, $data['brand_name'] ?? null, $category);

            $product = Product::create([
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'draft',
            ]);

            $this->attachAttributes($product, $category, $data['attributes'] ?? []);
            $this->syncSpecs($product, $category, $data['specs'] ?? []);
            $this->variants->add($product, $data['variants'] ?? []);
            $this->images->add($product, $data['images'] ?? []);

            return $this->find($product->id);
        });
    }

    public function update(Product $product, array $data): Product
    {
        if (array_key_exists('brand_id', $data) || array_key_exists('brand_name', $data)) {
            $product->brand_id = $this->resolveBrand($data['brand_id'] ?? null, $data['brand_name'] ?? null, $product->category)?->id;
        }

        if (isset($data['name'])) {
            $product->name = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $product->description = $data['description'];
        }

        if (isset($data['status'])) {
            if ($data['status'] === 'active' && ! $product->variants()->where('status', 'active')->exists()) {
                $this->fail('status', 'Cannot publish a product without an active variant.');
            }

            $product->status = $data['status'];
        }

        DB::transaction(function () use ($product) {
            $product->save();

            if ($product->wasChanged('name')) {
                CatalogEvents::productUpserted($product);
            }
        });

        return $this->find($product->id);
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            CatalogEvents::productDeleted($product);
            $product->delete();
        });
    }

    private function resolveBrand(?int $id, ?string $name, Category $category): ?Brand
    {
        if ($id) {
            $brand = Brand::find($id) ?? $this->fail('brand_id', 'Brand not found.');
            $field = 'brand_id';
        } else {
            $name = trim((string) $name);

            if ($name === '') {
                return null;
            }

            $slug = Str::slug($name);

            if ($slug === '') {
                $this->fail('brand_name', 'Invalid brand name.');
            }

            $brand = Brand::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
            $field = 'brand_name';
        }

        if (! $brand->is_active) {
            $this->fail($field, "Brand [{$brand->name}] is inactive. Re-activate it first.");
        }

        $brand->categories()->syncWithoutDetaching([$category->id]);

        return $brand;
    }

    private function attachAttributes(Product $product, Category $category, array $definitions): void
    {
        $allowed = $category->attributes()->get()->keyBy('id');
        $sync = [];
        $valueIds = [];

        foreach (array_values($definitions) as $i => $def) {
            $attribute = $allowed->get((int) $def['attribute_id']);

            if (! $attribute || ! (bool) $attribute->pivot->is_variant) {
                $this->fail("attributes.$i.attribute_id", 'Not a variant attribute of this category.');
            }

            if (isset($sync[$attribute->id])) {
                $this->fail("attributes.$i.attribute_id", 'Attribute listed twice.');
            }

            $ids = array_values(array_unique(array_map('intval', $def['value_ids'] ?? [])));
            $valid = AttributeValue::where('attribute_id', $attribute->id)->whereIn('id', $ids)->count();

            if ($ids === [] || $valid !== count($ids)) {
                $this->fail("attributes.$i.value_ids", 'Select valid values for this attribute.');
            }

            $sync[$attribute->id] = ['sort_order' => $attribute->pivot->sort_order, 'is_variant' => true];
            $valueIds = array_merge($valueIds, $ids);
        }

        $product->attributes()->sync($sync);
        $product->attributeValues()->sync($valueIds);
    }

    public function setSpecs(Product $product, array $definitions): Product
    {
        DB::transaction(fn () => $this->syncSpecs($product, $product->category, $definitions));

        return $this->find($product->id);
    }

    private function syncSpecs(Product $product, Category $category, array $definitions): void
    {
        $allowed = $category->attributes()->wherePivot('is_variant', false)->get()->keyBy('id');
        $attach = [];
        $valueIds = [];

        foreach (array_values($definitions) as $i => $def) {
            $attribute = $allowed->get((int) ($def['attribute_id'] ?? 0));

            if (! $attribute) {
                $this->fail("specs.$i.attribute_id", 'Not a fixed (non-variant) attribute of this category.');
            }

            if (isset($attach[$attribute->id])) {
                $this->fail("specs.$i.attribute_id", 'Attribute listed twice.');
            }

            $ids = array_map('intval', $def['value_ids'] ?? []);

            foreach ($def['values'] ?? [] as $text) {
                if (trim((string) $text) !== '') {
                    $ids[] = $this->attributes->addValue($attribute, (string) $text)->id;
                }
            }

            $ids = array_values(array_unique($ids));
            $valid = AttributeValue::where('attribute_id', $attribute->id)->whereIn('id', $ids)->count();

            if ($ids === [] || $valid !== count($ids)) {
                $this->fail("specs.$i.value_ids", 'Pick or type at least one valid value for this attribute.');
            }

            $attach[$attribute->id] = ['sort_order' => $attribute->pivot->sort_order, 'is_variant' => false];
            $valueIds = array_merge($valueIds, $ids);
        }

        $old = DB::table('product_attributes')->where('product_id', $product->id)->where('is_variant', false);
        $oldAttributeIds = (clone $old)->pluck('attribute_id');

        if ($oldAttributeIds->isNotEmpty()) {
            DB::table('product_attribute_assignments')
                ->where('product_id', $product->id)
                ->whereIn('attribute_value_id', AttributeValue::whereIn('attribute_id', $oldAttributeIds)->select('id'))
                ->delete();
            $old->delete();
        }

        foreach ($attach as $attributeId => $pivot) {
            $product->attributes()->attach($attributeId, $pivot);
        }

        $product->attributeValues()->syncWithoutDetaching($valueIds);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $i = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
