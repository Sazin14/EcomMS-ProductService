<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Services\Concerns\FailsValidation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AttributeService
{
    use FailsValidation;

    /** Step 1 of the admin form: variant attributes of a category, each with its predefined values. */
    public function forCategory(int $categoryId): Collection
    {
        return Category::findOrFail($categoryId)
            ->attributes()
            ->wherePivot('is_variant', true)
            ->with(['values' => fn ($q) => $q->orderBy('sort_order')->orderBy('value')])
            ->get();
    }

    /** "Add new value" button: returns the existing value if the same text already exists. */
    public function addValue(Attribute $attribute, string $value): AttributeValue
    {
        $value = trim($value);

        if ($value === '') {
            $this->fail('value', 'Value cannot be empty.');
        }

        $existing = AttributeValue::where('attribute_id', $attribute->id)
            ->whereRaw('lower(value) = ?', [mb_strtolower($value)])
            ->first();

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
}
