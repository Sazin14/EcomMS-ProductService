<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\CatalogService;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(private CatalogService $catalog) {}

    public function categories()
    {
        return Category::where('is_active', true)->orderBy('name')->get(['id', 'parent_id', 'name', 'slug']);
    }

    public function products(Request $request)
    {
        $filters = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'brand_ids' => ['nullable', 'array'],
            'brand_ids.*' => ['integer'],
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['array'],
            'attributes.*.*' => ['integer'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->catalog->products($filters);
    }

    /** Sidebar: brands, attribute values and price range for a category. */
    public function filters(Request $request)
    {
        $data = $request->validate(['category_id' => ['required', 'integer']]);

        return $this->catalog->facets($data['category_id']);
    }

    public function show(string $slug)
    {
        return $this->catalog->show($slug);
    }
}
