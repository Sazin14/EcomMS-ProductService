<?php

namespace App\Services;

use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProductService
{
    /*
    |--------------------------------------------------------------------------
    | Product Listing
    |--------------------------------------------------------------------------
    */

    public function list(array $filters = [])
    {
        $query = Product::query()
            ->with([
                'category',
                'brand',
                'attributes',
                'variants.attributeValues.attribute',
                'variants.attributeValues.attributeValue',
                'images.attributeValue.attribute',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Category
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['category_id'])) {
            $query->where(
                'category_id',
                $filters['category_id']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Brand
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['brand_id'])) {
            $query->where(
                'brand_id',
                $filters['brand_id']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['status'])) {
            $query->where(
                'status',
                $filters['status']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Attribute Value Filters
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | attribute_filters = [
        |     1 => [1, 2],     // RAM: 8GB, 16GB
        |     2 => [4, 5],     // Storage
        |     3 => [10]        // Color: Green
        | ]
        |
        */

        if (! empty($filters['attribute_filters'])) {
            foreach ($filters['attribute_filters'] as $attributeId => $valueIds) {

                $valueIds = array_map(
                    'intval',
                    (array) $valueIds
                );

                $query->whereHas(
                    'variants.attributeValues',
                    function (Builder $q) use (
                        $attributeId,
                        $valueIds
                    ) {
                        $q->where(
                            'attribute_id',
                            $attributeId
                        )->whereIn(
                            'attribute_value_id',
                            $valueIds
                        );
                    }
                );
            }
        }

        return $query
            ->latest()
            ->paginate(
                $filters['per_page'] ?? 20
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Find Product
    |--------------------------------------------------------------------------
    */

    public function find(int $productId): Product
    {
        return Product::with([
            'category',
            'brand',
            'attributes',
            'variants.attributeValues.attribute',
            'variants.attributeValues.attributeValue',
            'images.attributeValue.attribute',
        ])->findOrFail($productId);
    }


    /*
    |--------------------------------------------------------------------------
    | Create Product
    |--------------------------------------------------------------------------
    */

    public function create(array $data): array
{
    return DB::transaction(function () use ($data) {

        /*
        |--------------------------------------------------------------------------
        | Category
        |--------------------------------------------------------------------------
        */

        $category = Category::findOrFail(
            $data['category_id']
        );

        if (! $category->is_active) {
            throw new RuntimeException(
                'The selected category is inactive.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Brand
        |--------------------------------------------------------------------------
        */

        if (! empty($data['brand_id'])) {

            $brand = Brand::findOrFail(
                $data['brand_id']
            );

            if (! $brand->is_active) {
                throw new RuntimeException(
                    'The selected brand is inactive.'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Create Product
        |--------------------------------------------------------------------------
        */

        $product = Product::create([
            'category_id' => $category->id,
            'brand_id' => $data['brand_id'] ?? null,
            'name' => $data['name'],
            'slug' => $this->generateUniqueSlug(
                $data['name']
            ),
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'draft',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Product Attributes
        |--------------------------------------------------------------------------
        */

        $attributeDefinitions = $data['attributes'] ?? [];

        if (empty($attributeDefinitions)) {
            throw new RuntimeException(
                'At least one product attribute is required.'
            );
        }

        $this->validateAndAssignAttributes(
            product: $product,
            category: $category,
            attributeDefinitions: $attributeDefinitions
        );

        /*
        |--------------------------------------------------------------------------
        | Resolve Attribute Values
        |--------------------------------------------------------------------------
        */

        $resolvedAttributes =
            $this->resolveProductAttributeValues(
                $attributeDefinitions
            );

        /*
        |--------------------------------------------------------------------------
        | Generate Combinations
        |--------------------------------------------------------------------------
        */

        $combinations = $this->cartesianProduct(
            array_values($resolvedAttributes)
        );

        return [
            'product' => $product->load([
                'category',
                'brand',
                'attributes',
            ]),

            'combinations' => $this->formatCombinations(
                $combinations
            ),

            'variant_count' => count($combinations),
        ];
    });
}



    private function resolveProductAttributeValues(
        array $attributeDefinitions
    ): array {

        $resolved = [];

        foreach ($attributeDefinitions as $definition) {

            $attributeId = (int) $definition['attribute_id'];

            $values = [];

            foreach ($definition['values'] as $valueData) {

                $attributeValue = $this->resolveAttributeValue(
                    attributeId: $attributeId,
                    valueData: $valueData
                );

                $values[] = $attributeValue;
            }

            if (empty($values)) {
                throw new RuntimeException(
                    "Attribute [{$attributeId}] must contain at least one value."
                );
            }

            $resolved[$attributeId] = $values;
        }

        return $resolved;
    }


    private function formatCombinations(
        array $combinations
    ): array {

        return array_map(
            function (array $combination) {

                return [
                    'attributes' => collect($combination)
                        ->map(function (AttributeValue $value) {
                            return [
                                'attribute_id' => $value->attribute_id,
                                'attribute_value_id' => $value->id,
                                'value' => $value->value,
                            ];
                        })
                        ->values()
                        ->toArray(),
                ];
            },
            $combinations
        );
    }


    

    /*
    |--------------------------------------------------------------------------
    | Validate + Assign Product Attributes
    |--------------------------------------------------------------------------
    */

    private function validateAndAssignAttributes(
        Product $product,
        Category $category,
        array $attributeDefinitions
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Attributes allowed for this category
        |--------------------------------------------------------------------------
        */

        $categoryAttributes = $category
            ->attributes()
            ->get()
            ->keyBy('id');

        foreach ($attributeDefinitions as $definition) {

            $attributeId = (int) $definition['attribute_id'];

            /*
            |--------------------------------------------------------------------------
            | Check category attribute
            |--------------------------------------------------------------------------
            */

            if (! $categoryAttributes->has($attributeId)) {
                throw new RuntimeException(
                    "Attribute [{$attributeId}] is not available for this category."
                );
            }

            $categoryAttribute = $categoryAttributes->get(
                $attributeId
            );

            /*
            |--------------------------------------------------------------------------
            | Variant Attribute
            |--------------------------------------------------------------------------
            |
            | Currently these selected values are used to generate variants,
            | so the category must mark this attribute as variant-defining.
            |
            */

            if (! $categoryAttribute->pivot->is_variant) {
                throw new RuntimeException(
                    "Attribute [{$attributeId}] is not a variant attribute."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Attach Attribute To Product
            |--------------------------------------------------------------------------
            */

            $product->attributes()->syncWithoutDetaching([
                $attributeId => [
                    'sort_order' => $categoryAttribute->pivot->sort_order,
                ],
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Create Variants
    |--------------------------------------------------------------------------
    */

    public function createVariants(
        Product $product,
        array $variants
    ): Product {

        return DB::transaction(function () use (
            $product,
            $variants
        ) {

            if (empty($variants)) {
                throw new RuntimeException(
                    'At least one variant is required.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Product Attributes
            |--------------------------------------------------------------------------
            */

            $productAttributeIds = $product
                ->attributes()
                ->pluck('attributes.id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            /*
            |--------------------------------------------------------------------------
            | Create Variants
            |--------------------------------------------------------------------------
            */

            foreach ($variants as $variantData) {

                $attributeValues =
                    $variantData['attribute_values'] ?? [];

                if (empty($attributeValues)) {
                    throw new RuntimeException(
                        'Every variant must contain attribute values.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Validate number of attributes
                |--------------------------------------------------------------------------
                */

                if (
                    count($attributeValues)
                    !== count($productAttributeIds)
                ) {
                    throw new RuntimeException(
                        'Every variant must contain exactly one value for every product attribute.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Validate Attribute Values
                |--------------------------------------------------------------------------
                */

                $resolvedValues = [];

                foreach ($attributeValues as $attributeValueId) {

                    $attributeValue =
                        AttributeValue::findOrFail(
                            $attributeValueId
                        );

                    if (
                        ! in_array(
                            $attributeValue->attribute_id,
                            $productAttributeIds,
                            true
                        )
                    ) {
                        throw new RuntimeException(
                            "Attribute value [{$attributeValueId}] does not belong to this product."
                        );
                    }

                    $resolvedValues[] = $attributeValue;
                }

                /*
                |--------------------------------------------------------------------------
                | Prevent duplicate attribute combinations
                |--------------------------------------------------------------------------
                */

                $combination = collect($resolvedValues)
                    ->sortBy('attribute_id')
                    ->map(
                        fn ($value) =>
                            $value->attribute_id . ':' . $value->id
                    )
                    ->implode('|');

                $existingVariants = $product
                    ->variants()
                    ->with('attributeValues')
                    ->get();

                foreach ($existingVariants as $existingVariant) {

                    $existingCombination =
                        $existingVariant
                            ->attributeValues
                            ->sortBy('attribute_id')
                            ->map(
                                fn ($value) =>
                                    $value->attribute_id
                                    . ':'
                                    . $value->attribute_value_id
                            )
                            ->implode('|');

                    if ($existingCombination === $combination) {
                        throw new RuntimeException(
                            'Duplicate variant combination detected.'
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Create Variant
                |--------------------------------------------------------------------------
                */

                $variant = $product->variants()->create([
                    'sku' => $variantData['sku'],
                    'purchase_price' =>
                        $variantData['purchase_price'],

                    'selling_price' =>
                        $variantData['selling_price'],

                    'status' =>
                        $variantData['status'] ?? 'active',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Create Variant Attribute Values
                |--------------------------------------------------------------------------
                */

                foreach ($resolvedValues as $attributeValue) {

                    $variant->attributeValues()->create([
                        'attribute_id' =>
                            $attributeValue->attribute_id,

                        'attribute_value_id' =>
                            $attributeValue->id,
                    ]);
                }
            }

            return $product->fresh([
                'attributes',
                'variants.attributeValues.attribute',
                'variants.attributeValues.attributeValue',
            ]);
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Resolve Attribute Value
    |--------------------------------------------------------------------------
    */

    private function resolveAttributeValue(
        int $attributeId,
        array $valueData
    ): AttributeValue {

        /*
        |--------------------------------------------------------------------------
        | Existing value
        |--------------------------------------------------------------------------
        */

        if (! empty($valueData['id'])) {

            $attributeValue = AttributeValue::where(
                'attribute_id',
                $attributeId
            )->find($valueData['id']);

            if (! $attributeValue) {
                throw new RuntimeException(
                    "Attribute value [{$valueData['id']}] does not belong to attribute [{$attributeId}]."
                );
            }

            return $attributeValue;
        }

        /*
        |--------------------------------------------------------------------------
        | New value
        |--------------------------------------------------------------------------
        */

        if (empty($valueData['value'])) {
            throw new RuntimeException(
                'Attribute value must contain either an id or value.'
            );
        }

        $value = trim($valueData['value']);

        $existing = AttributeValue::where(
            'attribute_id',
            $attributeId
        )
            ->where('value', $value)
            ->first();

        if ($existing) {
            return $existing;
        }

        return AttributeValue::create([
            'attribute_id' => $attributeId,
            'value' => $value,
            'slug' => $this->generateAttributeValueSlug(
                $attributeId,
                $value
            ),
            'sort_order' => 0,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Cartesian Product
    |--------------------------------------------------------------------------
    */

    private function cartesianProduct(
        array $groups
    ): array {

        $result = [[]];

        foreach ($groups as $group) {

            $newResult = [];

            foreach ($result as $combination) {

                foreach ($group as $value) {

                    $newResult[] = array_merge(
                        $combination,
                        [$value]
                    );
                }
            }

            $result = $newResult;
        }

        return $result;
    }


    /*
    |--------------------------------------------------------------------------
    | Update Product
    |--------------------------------------------------------------------------
    */

    public function update(
        Product $product,
        array $data
    ): Product {

        return DB::transaction(function () use (
            $product,
            $data
        ) {

            if (isset($data['category_id'])) {

                $category = Category::findOrFail(
                    $data['category_id']
                );

                if (! $category->is_active) {
                    throw new RuntimeException(
                        'The selected category is inactive.'
                    );
                }

                $product->category_id = $category->id;
            }

            if (array_key_exists('brand_id', $data)) {

                if ($data['brand_id'] !== null) {

                    $brand = Brand::findOrFail(
                        $data['brand_id']
                    );

                    if (! $brand->is_active) {
                        throw new RuntimeException(
                            'The selected brand is inactive.'
                        );
                    }
                }

                $product->brand_id = $data['brand_id'];
            }

            if (isset($data['name'])) {

                $product->name = $data['name'];

                $product->slug = $this->generateUniqueSlug(
                    $data['name'],
                    $product->id
                );
            }

            if (array_key_exists('description', $data)) {
                $product->description =
                    $data['description'];
            }

            if (isset($data['status'])) {
                $product->status = $data['status'];
            }

            $product->save();

            return $product->fresh([
                'category',
                'brand',
                'attributes',
                'variants.attributeValues.attribute',
                'variants.attributeValues.attributeValue',
                'images.attributeValue.attribute',
            ]);
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Delete Product
    |--------------------------------------------------------------------------
    */

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $product->delete();
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Category Attributes
    |--------------------------------------------------------------------------
    */

    public function getCategoryAttributes(
        int $categoryId
    ) {
        $category = Category::findOrFail(
            $categoryId
        );

        return $category
            ->attributes()
            ->with('values')
            ->orderBy('category_attributes.sort_order')
            ->get();
    }


    /*
    |--------------------------------------------------------------------------
    | Add Images
    |--------------------------------------------------------------------------
    */

    public function addImages(
        Product $product,
        array $images
    ): void {

        DB::transaction(function () use (
            $product,
            $images
        ) {

            foreach ($images as $image) {

                $attributeValueId =
                    $image['attribute_value_id'] ?? null;

                /*
                |--------------------------------------------------------------------------
                | General Image
                |--------------------------------------------------------------------------
                */

                if ($attributeValueId === null) {

                    $product->images()->create([
                        'attribute_value_id' => null,
                        'url' => $image['url'],
                        'sort_order' =>
                            $image['sort_order'] ?? 0,
                        'is_primary' =>
                            $image['is_primary'] ?? false,
                    ]);

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Validate Attribute Value
                |--------------------------------------------------------------------------
                */

                $attributeValue = AttributeValue::findOrFail(
                    $attributeValueId
                );

                $belongsToProduct =
                    $product->attributes()
                        ->where(
                            'attributes.id',
                            $attributeValue->attribute_id
                        )
                        ->exists();

                if (! $belongsToProduct) {
                    throw new RuntimeException(
                        'The selected attribute value does not belong to this product.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Create Attribute-Value Image
                |--------------------------------------------------------------------------
                */

                $product->images()->create([
                    'attribute_value_id' =>
                        $attributeValue->id,

                    'url' => $image['url'],

                    'sort_order' =>
                        $image['sort_order'] ?? 0,

                    'is_primary' =>
                        $image['is_primary'] ?? false,
                ]);
            }
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Delete Image
    |--------------------------------------------------------------------------
    */

    public function deleteImage(
        ProductImage $image
    ): void {
        $image->delete();
    }


    /*
    |--------------------------------------------------------------------------
    | Slug Helpers
    |--------------------------------------------------------------------------
    */

    private function generateUniqueSlug(
        string $name,
        ?int $ignoreId = null
    ): string {

        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            throw new RuntimeException(
                'Unable to generate a valid product slug.'
            );
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Product::where('slug', $slug)
                ->when(
                    $ignoreId,
                    fn ($query) =>
                        $query->where('id', '!=', $ignoreId)
                )
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }


    private function generateAttributeValueSlug(
        int $attributeId,
        string $value
    ): string {

        $baseSlug = Str::slug($value);

        if ($baseSlug === '') {
            $baseSlug = 'value';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            AttributeValue::where(
                'attribute_id',
                $attributeId
            )
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }


    private function generateTemporarySku(
        Product $product
    ): string {

        do {
            $sku =
                strtoupper(
                    Str::slug($product->name)
                )
                . '-'
                . strtoupper(
                    Str::random(8)
                );

        } while (
            ProductVariant::where('sku', $sku)->exists()
        );

        return $sku;
    }
}