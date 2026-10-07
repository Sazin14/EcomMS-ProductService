<?php

namespace App\Services;

use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Concerns\FailsValidation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VariantService
{
    use FailsValidation;

    public const MAX_COMBINATIONS = 500;

    /**
     * Builds the rows of the combination table (8GB/128GB/Grey ...). Saves nothing.
     *
     * @param  array  $definitions  [['attribute_id' => 1, 'value_ids' => [1, 2]], ...]
     */
    public function preview(array $definitions): array
    {
        $groups = [];

        foreach (array_values($definitions) as $i => $def) {
            $wanted = array_unique(array_map('intval', $def['value_ids'] ?? []));

            $values = AttributeValue::where('attribute_id', $def['attribute_id'])
                ->whereIn('id', $wanted)
                ->orderBy('sort_order')->orderBy('id')
                ->get();

            if ($values->isEmpty() || $values->count() !== count($wanted)) {
                $this->fail("attributes.$i.value_ids", 'Invalid values for this attribute.');
            }

            $groups[] = $values->all();
        }

        if (array_product(array_map('count', $groups)) > self::MAX_COMBINATIONS) {
            $this->fail('attributes', 'Too many combinations (max '.self::MAX_COMBINATIONS.').');
        }

        return array_map(fn (array $combo) => [
            'attribute_value_ids' => array_map(fn ($v) => $v->id, $combo),
            'label' => implode(' / ', array_map(fn ($v) => $v->value, $combo)),
        ], $this->cartesian($groups));
    }

    /**
     * Saves variants for a product. Used when creating a product and when adding more later.
     * Each row must pick exactly one value for every attribute the product uses.
     * A product without attributes (simple product) gets exactly one row with an empty value list.
     *
     * @param  array  $rows  [['attribute_value_ids' => [1, 4, 10], 'sku' => '', 'purchase_price' => 500,
     *                         'selling_price' => 650, 'stock' => 10], ...]
     */
    public function add(Product $product, array $rows): Collection
    {
        if ($rows === []) {
            $this->fail('variants', 'At least one variant is required.');
        }

        $productAttributeIds = $product->attributes()->pluck('attributes.id')
            ->map(fn ($id) => (int) $id)->sort()->values()->all();

        // One query for every value used in the request.
        $allValueIds = collect($rows)->pluck('attribute_value_ids')->flatten()
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        $values = AttributeValue::whereIn('id', $allValueIds)->get()->keyBy('id');

        // SKUs typed by the admin must be unique (blank ones are generated below).
        $givenSkus = collect($rows)->pluck('sku')->map(fn ($s) => trim((string) $s))->filter()->values();

        if ($givenSkus->count() !== $givenSkus->unique()->count()) {
            $this->fail('variants', 'Two variants use the same SKU.');
        }

        if ($clash = ProductVariant::whereIn('sku', $givenSkus)->value('sku')) {
            $this->fail('variants', "SKU [$clash] already exists.");
        }

        $usedHashes = $product->variants()->pluck('combination_hash')->flip()->all();
        $prepared = [];

        foreach (array_values($rows) as $i => $row) {
            $ids = array_values(array_unique(array_map('intval', $row['attribute_value_ids'] ?? [])));
            $picked = collect($ids)->map(fn ($id) => $values->get($id));

            if ($picked->contains(fn ($v) => $v === null)) {
                $this->fail("variants.$i.attribute_value_ids", 'Unknown attribute value.');
            }

            $pickedAttributeIds = $picked->pluck('attribute_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

            if ($pickedAttributeIds !== $productAttributeIds) {
                $this->fail("variants.$i.attribute_value_ids", 'Pick exactly one value for each product attribute.');
            }

            $hash = $this->hash($ids);

            if (isset($usedHashes[$hash])) {
                $this->fail("variants.$i", 'This combination already exists.');
            }

            $usedHashes[$hash] = true;
            $prepared[] = compact('picked', 'hash', 'row');
        }

        $created = collect();
        $pivotRows = [];

        foreach ($prepared as $item) {
            $row = $item['row'];

            $variant = $product->variants()->create([
                'sku' => trim((string) ($row['sku'] ?? '')) ?: $this->generateSku($product),
                'combination_hash' => $item['hash'],
                'purchase_price' => $row['purchase_price'],
                'selling_price' => $row['selling_price'],
                'stock' => (int) ($row['stock'] ?? 0),
                'status' => $row['status'] ?? 'active',
            ]);

            foreach ($item['picked'] as $value) {
                $pivotRows[] = [
                    'variant_id' => $variant->id,
                    'attribute_id' => $value->attribute_id,
                    'attribute_value_id' => $value->id,
                ];
            }

            $created->push($variant);
        }

        DB::table('variant_attribute_values')->insert($pivotRows);

        // Keep the product's "selected values" list in sync (images are validated against it).
        $product->attributeValues()->syncWithoutDetaching($allValueIds);

        return $created;
    }

    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        if (isset($data['sku']) && $data['sku'] !== $variant->sku
            && ProductVariant::where('sku', $data['sku'])->where('id', '!=', $variant->id)->exists()) {
            $this->fail('sku', 'This SKU already exists.');
        }

        $variant->update(Arr::only($data, ['sku', 'purchase_price', 'selling_price', 'stock', 'status']));

        return $variant->fresh('attributeValues.attribute');
    }

    public function remove(ProductVariant $variant): void
    {
        if ($variant->product->variants()->count() <= 1) {
            $this->fail('variant', 'A product must keep at least one variant.');
        }

        $variant->delete();
    }

    /** Same value set in any order gives the same hash. */
    private function hash(array $valueIds): string
    {
        sort($valueIds);

        return md5(implode('-', $valueIds));
    }

    private function generateSku(Product $product): string
    {
        $prefix = strtoupper(Str::limit(Str::slug($product->name, ''), 10, '')) ?: 'SKU';

        do {
            $sku = $prefix.'-'.strtoupper(Str::random(6));
        } while (ProductVariant::where('sku', $sku)->exists());

        return $sku;
    }

    private function cartesian(array $groups): array
    {
        $result = [[]];

        foreach ($groups as $group) {
            $next = [];

            foreach ($result as $combo) {
                foreach ($group as $value) {
                    $next[] = [...$combo, $value];
                }
            }

            $result = $next;
        }

        return $result;
    }
}
