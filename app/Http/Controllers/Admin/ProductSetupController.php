<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Services\AttributeService;
use App\Services\BrandService;
use Illuminate\Http\Request;

class ProductSetupController extends Controller
{
    public function __construct(
        private AttributeService $attributes,
        private BrandService $brands,
    ) {}

    public function categoryAttributes(Request $request, int $category)
    {
        $variant = $request->has('variant') ? $request->boolean('variant') : null;

        return $this->attributes->forCategory($category, $variant);
    }

    public function addValue(Request $request, Attribute $attribute)
    {
        $data = $request->validate(['value' => ['required', 'string', 'max:100']]);

        return response()->json($this->attributes->addValue($attribute, $data['value']), 201);
    }

    public function brands(Request $request)
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'all' => ['nullable', 'boolean'],
        ]);

        return $this->brands->list($data['category_id'] ?? null, (bool) ($data['all'] ?? false));
    }
}
