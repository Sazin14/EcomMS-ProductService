<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Concerns\FailsValidation;
use Illuminate\Support\Facades\DB;

class ProductImageService
{
    use FailsValidation;

    /**
     * @param  array  $images  [['url' => '...', 'attribute_value_id' => 10|null, 'is_primary' => true, 'sort_order' => 0], ...]
     *                         attribute_value_id = null means a general image shown for every variant.
     */
    public function add(Product $product, array $images): void
    {
        if ($images === []) {
            return;
        }

        $selected = $product->attributeValues()->pluck('attribute_values.id')->map(fn ($id) => (int) $id)->all();

        foreach (array_values($images) as $i => $img) {
            $valueId = $img['attribute_value_id'] ?? null;

            if ($valueId !== null && ! in_array((int) $valueId, $selected, true)) {
                $this->fail("images.$i.attribute_value_id", 'This value is not used by the product.');
            }
        }

        $now = now();
        $rows = [];

        // Each scope ("general", "Space Grey", "Ocean Blue"...) keeps exactly one primary image.
        $groups = collect($images)->groupBy(fn ($img) => $img['attribute_value_id'] ?? 'general');

        foreach ($groups as $scope => $items) {
            $items = $items->values();
            $flagged = $items->search(fn ($img) => ! empty($img['is_primary']));

            if ($flagged !== false) {
                $this->scope($product, $scope)->update(['is_primary' => false]);
                $primary = $flagged;
            } else {
                $primary = $this->scope($product, $scope)->where('is_primary', true)->exists() ? null : 0;
            }

            foreach ($items as $n => $img) {
                $rows[] = [
                    'product_id' => $product->id,
                    'attribute_value_id' => $img['attribute_value_id'] ?? null,
                    'url' => $img['url'],
                    'sort_order' => $img['sort_order'] ?? $n,
                    'is_primary' => $n === $primary,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        ProductImage::insert($rows);
    }

    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            $image->delete();

            if ($image->is_primary) {
                // Promote the next image of the same scope.
                ProductImage::where('product_id', $image->product_id)
                    ->when(
                        $image->attribute_value_id === null,
                        fn ($q) => $q->whereNull('attribute_value_id'),
                        fn ($q) => $q->where('attribute_value_id', $image->attribute_value_id)
                    )
                    ->orderBy('sort_order')->orderBy('id')
                    ->first()?->update(['is_primary' => true]);
            }
        });
    }

    private function scope(Product $product, int|string $scope)
    {
        return $product->images()->reorder()->when(
            $scope === 'general',
            fn ($q) => $q->whereNull('attribute_value_id'),
            fn ($q) => $q->where('attribute_value_id', $scope)
        );
    }
}
