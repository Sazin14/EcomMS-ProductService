<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Category;
use App\Services\AttributeService;
use App\Services\CategoryService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(
        private CategoryService $categories,
        private AttributeService $attributes,
    ) {}

    public function index()
    {
        return ['data' => $this->categories->tree(onlyActive: false)];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->categories->create($data), 201);
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $this->categories->update($category, $data);
    }

    public function destroy(Category $category)
    {
        $this->categories->delete($category);

        return response()->noContent();
    }

    public function attachAttribute(Request $request, Category $category)
    {
        $data = $request->validate([
            'attribute_id' => ['required', 'integer', 'exists:attributes,id'],
            'is_variant' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->categories->attachAttribute(
            $category,
            Attribute::findOrFail($data['attribute_id']),
            (bool) $data['is_variant'],
            $data['sort_order'] ?? null,
        );

        return $this->attributes->forCategory($category->id);
    }

    public function detachAttribute(Category $category, Attribute $attribute)
    {
        $this->categories->detachAttribute($category, $attribute);

        return response()->noContent();
    }
}
