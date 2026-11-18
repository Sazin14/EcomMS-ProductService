<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Services\Concerns\FailsValidation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttributeService
{
    use FailsValidation;

    public function forCategory(int $categoryId, ?bool $variant = null): Collection
    {
        $relation = Category::findOrFail($categoryId)->attributes();

        if ($variant !== null) {
            $relation->wherePivot('is_variant', $variant);
        }

        return $relation
            ->with(['values' => fn ($q) => $q->orderBy('sort_order')->orderBy('value')])
            ->get();
    }

    public function all(): Collection
    {
        return Attribute::query()
            ->with([
                'values' => fn ($q) => $q->orderBy('sort_order')->orderBy('value'),
                'categories:categories.id,categories.name',
            ])
            ->orderBy('name')
            ->get();
    }

    public function create(array $data): Attribute
    {
        $name = trim($data['name']);
        $slug = Str::slug($name);

        if ($slug === '') {
            $this->fail('name', 'Invalid attribute name.');
        }

        if (Attribute::where('slug', $slug)->exists()) {
            $this->fail('name', "Attribute [$name] already exists.");
        }

        return Attribute::create([
            'name' => $name,
            'slug' => $slug,
            'type' => $data['type'] ?? 'select',
        ]);
    }

    public function update(Attribute $attribute, array $data): Attribute
    {
        if (isset($data['name'])) {
            $name = trim($data['name']);

            if (Attribute::where('slug', Str::slug($name))->where('id', '!=', $attribute->id)->exists()) {
                $this->fail('name', "Attribute [$name] already exists.");
            }

            $attribute->name = $name;
        }

        if (isset($data['type'])) {
            $attribute->type = $data['type'];
        }

        $attribute->save();

        return $attribute;
    }

    public function delete(Attribute $attribute): void
    {
        $used = DB::table('product_attributes')->where('attribute_id', $attribute->id)->exists()
            || DB::table('variant_attribute_values')->where('attribute_id', $attribute->id)->exists();

        if ($used) {
            $this->fail('attribute', 'This attribute is used by products and cannot be deleted.');
        }

        $attribute->delete();
    }

    public function addValue(Attribute $attribute, string $value): AttributeValue
    {
        $value = trim($value);

        if ($value === '') {
            $this->fail('value', 'Value cannot be empty.');
        }

        $existing = $this->findValue($attribute, $value);

        if ($existing) {
            return $existing;
        }

        $base = Str::slug($value) ?: 'value';
        $slug = $base;
        $i = 2;

        while (AttributeValue::where('attribute_id', $attribute->id)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => $value,
            'slug' => $slug,
            'sort_order' => ((int) AttributeValue::where('attribute_id', $attribute->id)->max('sort_order')) + 1,
        ]);
    }

    public function updateValue(AttributeValue $value, array $data): AttributeValue
    {
        if (isset($data['value'])) {
            $text = trim($data['value']);
            $clash = $this->findValue($value->attribute, $text);

            if ($clash && $clash->id !== $value->id) {
                $this->fail('value', "Value [$text] already exists for this attribute.");
            }

            $value->value = $text;
        }

        if (isset($data['sort_order'])) {
            $value->sort_order = (int) $data['sort_order'];
        }

        $value->save();

        return $value;
    }

    public function deleteValue(AttributeValue $value): void
    {
        $used = DB::table('variant_attribute_values')->where('attribute_value_id', $value->id)->exists()
            || DB::table('product_attribute_assignments')->where('attribute_value_id', $value->id)->exists()
            || DB::table('product_images')->where('attribute_value_id', $value->id)->exists();

        if ($used) {
            $this->fail('value', 'This value is used by products and cannot be deleted.');
        }

        $value->delete();
    }

    private function findValue(Attribute $attribute, string $text): ?AttributeValue
    {
        return AttributeValue::where('attribute_id', $attribute->id)
            ->whereRaw('lower(value) = ?', [mb_strtolower($text)])
            ->first();
    }
}
