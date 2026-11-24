<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\Category;
use App\Services\Concerns\FailsValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryService
{
    use FailsValidation;

    public function tree(bool $onlyActive = true): array
    {
        $rows = Category::query()
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->when(! $onlyActive, fn ($q) => $q->withCount('products'))
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Category $c) => $c->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $rows): array {
            return ($rows->get($parentId) ?? collect())
                ->map(function (Category $c) use ($build) {
                    $node = $c->only(['id', 'parent_id', 'name', 'slug', 'is_active']);

                    if (isset($c->products_count)) {
                        $node['products_count'] = $c->products_count;
                    }

                    $node['children'] = $build($c->id);

                    return $node;
                })
                ->values()
                ->all();
        };

        return $build(0);
    }

    public function create(array $data): Category
    {
        $parent = null;

        if (! empty($data['parent_id'])) {
            $parent = Category::find($data['parent_id']) ?? $this->fail('parent_id', 'Parent category not found.');
        }

        return Category::create([
            'parent_id' => $parent?->id,
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug(trim($data['name']), $parent),
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function update(Category $category, array $data): Category
    {
        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] ? (int) $data['parent_id'] : null;

            if ($parentId !== null) {
                if (! Category::whereKey($parentId)->exists()) {
                    $this->fail('parent_id', 'Parent category not found.');
                }

                if (in_array($parentId, Category::subtreeIds($category->id), true)) {
                    $this->fail('parent_id', 'A category cannot be moved under itself or its own sub-categories.');
                }
            }

            $category->parent_id = $parentId;
        }

        if (isset($data['name'])) {
            $category->name = trim($data['name']);
        }

        if (array_key_exists('is_active', $data)) {
            $category->is_active = (bool) $data['is_active'];
        }

        $category->save();

        return $category;
    }

    public function delete(Category $category): void
    {
        if ($category->children()->exists()) {
            $this->fail('category', 'This category has sub-categories. Move or delete them first.');
        }

        if ($category->products()->withTrashed()->exists()) {
            $this->fail('category', 'This category has products. Deactivate it instead of deleting it.');
        }

        $category->delete();
    }

    public function attachAttribute(Category $category, Attribute $attribute, bool $isVariant, ?int $sortOrder = null): void
    {
        $conflict = DB::table('category_attributes')
            ->where('attribute_id', $attribute->id)
            ->where('category_id', '!=', $category->id)
            ->where('is_variant', ! $isVariant)
            ->exists();

        if ($conflict) {
            $this->fail('is_variant', 'This attribute is already used as '.($isVariant ? 'a fixed spec' : 'a variable attribute').' in another category.');
        }

        $current = $category->attributes()->where('attributes.id', $attribute->id)->first();

        if ($current && (bool) $current->pivot->is_variant !== $isVariant && $this->usedByProducts($category, $attribute)) {
            $this->fail('is_variant', 'Products in this category already use this attribute, so its type cannot change.');
        }

        $category->attributes()->syncWithoutDetaching([
            $attribute->id => [
                'is_variant' => $isVariant,
                'sort_order' => $sortOrder ?? $current?->pivot->sort_order ?? ((int) $category->attributes()->reorder()->max('category_attributes.sort_order')) + 1,
            ],
        ]);
    }

    public function detachAttribute(Category $category, Attribute $attribute): void
    {
        if ($this->usedByProducts($category, $attribute)) {
            $this->fail('attribute', 'Products in this category already use this attribute.');
        }

        $category->attributes()->detach($attribute->id);
    }

    private function usedByProducts(Category $category, Attribute $attribute): bool
    {
        return DB::table('product_attributes as pa')
            ->join('products as p', 'p.id', '=', 'pa.product_id')
            ->where('p.category_id', $category->id)
            ->where('pa.attribute_id', $attribute->id)
            ->exists();
    }

    private function uniqueSlug(string $name, ?Category $parent): string
    {
        $base = Str::slug($name) ?: 'category';
        $candidates = array_filter([$base, $parent ? $base.'-'.$parent->slug : null]);

        foreach ($candidates as $slug) {
            if (! Category::where('slug', $slug)->exists()) {
                return $slug;
            }
        }

        $slug = end($candidates);
        $i = 2;

        while (Category::where('slug', "{$slug}-{$i}")->exists()) {
            $i++;
        }

        return "{$slug}-{$i}";
    }
}
