<?php

namespace App\Services;

use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Concerns\FailsValidation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin side: create / edit / delete products.
 * Variants and images live in their own services; this class only orchestrates them.
 */
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
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        return Product::query()
            ->with(['category:id,name', 'brand:id,name', 'thumbnail'])
            ->withCount('variants')
            ->withMin('variants as min_price', 'selling_price')
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($filters['brand_id'] ?? null, fn ($q, $v) => $q->where('brand_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('name', 'ilike', '%'.trim($v).'%'))
            ->latest()
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function find(int $id): Product
    {
        return Product::with(self::DETAILS)->findOrFail($id);
    }

    /**
     * Creates the whole product in ONE transaction: product, attributes, variants, images.
     * If anything is invalid nothing is saved.
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $category = Category::findOrFail($data['category_id']);

            if (! $category->is_active) {
                $this->fail('category_id', 'The selected category is inactive.');
            }

            $brand = $this->resolveBrand($data['brand_id'] ?? null, $data['brand_name'] ?? null);

            $product = Product::create([
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'draft',
            ]);

            $this->attachAttributes($product, $category, $data['attributes'] ?? []);
            $this->variants->add($product, $data['variants'] ?? []);   // after attributes, before images
            $this->images->add($product, $data['images'] ?? []);

            return $this->find($product->id);
        });
    }

    /** Basic info only. Variants, images and category are changed through their own methods. */
    public function update(Product $product, array $data): Product
    {
        if (array_key_exists('brand_id', $data) || array_key_exists('brand_name', $data)) {
            $product->brand_id = $this->resolveBrand($data['brand_id'] ?? null, $data['brand_name'] ?? null)?->id;
        }

        // The slug is NOT regenerated on rename, so product URLs keep working.
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

        $product->save();

        return $this->find($product->id);
    }

    public function delete(Product $product): void
    {
        $product->delete();   // soft delete
    }

    /**
     * Existing brand by id, or a typed name:
     * the name is matched by slug so "apple" / "Apple" reuse the same row, otherwise a new brand is saved.
     */
    private function resolveBrand(?int $id, ?string $name): ?Brand
    {
        if ($id) {
            $brand = Brand::find($id) ?? $this->fail('brand_id', 'Brand not found.');
        } else {
            $name = trim((string) $name);

            if ($name === '') {
                return null;   // brand is optional
            }

            $slug = Str::slug($name);

            if ($slug === '') {
                $this->fail('brand_name', 'Invalid brand name.');
            }

            $brand = Brand::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
        }

        if (! $brand->is_active) {
            $this->fail('brand_id', 'The selected brand is inactive.');
        }

        return $brand;
    }

    /** Checks every chosen attribute/value against the category and stores them on the product. */
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

            $sync[$attribute->id] = ['sort_order' => $attribute->pivot->sort_order];
            $valueIds = array_merge($valueIds, $ids);
        }

        $product->attributes()->sync($sync);
        $product->attributeValues()->sync($valueIds);
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
