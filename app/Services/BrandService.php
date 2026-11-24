<?php

namespace App\Services;

use App\Models\Brand;
use App\Services\Concerns\FailsValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BrandService
{
    use FailsValidation;

    public function list(?int $categoryId = null, bool $all = false)
    {
        return Brand::query()
            ->when($categoryId && ! $all, fn ($q) => $q->whereHas('categories', fn ($c) => $c->where('categories.id', $categoryId)))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    public function create(array $data): Brand
    {
        $name = trim($data['name']);
        $slug = Str::slug($name);

        if ($slug === '') {
            $this->fail('name', 'Invalid brand name.');
        }

        if (Brand::where('slug', $slug)->exists()) {
            $this->fail('name', "Brand [$name] already exists.");
        }

        return DB::transaction(function () use ($name, $slug, $data) {
            $brand = Brand::create(['name' => $name, 'slug' => $slug, 'is_active' => $data['is_active'] ?? true]);
            $brand->categories()->sync($data['category_ids'] ?? []);

            return $brand->load('categories:categories.id,categories.name');
        });
    }

    public function update(Brand $brand, array $data): Brand
    {
        if (isset($data['name'])) {
            $name = trim($data['name']);

            if (Brand::where('slug', Str::slug($name))->where('id', '!=', $brand->id)->exists()) {
                $this->fail('name', "Brand [$name] already exists.");
            }

            $brand->name = $name;
        }

        if (array_key_exists('is_active', $data)) {
            $brand->is_active = (bool) $data['is_active'];
        }

        DB::transaction(function () use ($brand, $data) {
            $brand->save();

            if (array_key_exists('category_ids', $data)) {
                $keep = array_map('intval', $data['category_ids'] ?? []);

                $inUse = $brand->products()->withTrashed()->whereNotIn('category_id', $keep)->exists();

                if ($inUse) {
                    $this->fail('category_ids', 'Products of this brand exist in a category you removed.');
                }

                $brand->categories()->sync($keep);
            }
        });

        return $brand->load('categories:categories.id,categories.name');
    }

    public function delete(Brand $brand): void
    {
        if ($brand->products()->withTrashed()->exists()) {
            $this->fail('brand', 'This brand has products. Deactivate it instead of deleting it.');
        }

        $brand->delete();
    }
}
