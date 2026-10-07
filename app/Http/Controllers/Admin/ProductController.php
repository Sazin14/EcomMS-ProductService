<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\ProductImageService;
use App\Services\ProductService;
use App\Services\VariantService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $products,
        private VariantService $variants,
        private ProductImageService $images,
    ) {}

    public function index(Request $request)
    {
        return $this->products->list($request->query());
    }

    public function store(StoreProductRequest $request)
    {
        return response()->json($this->products->create($request->validated()), 201);
    }

    public function show(Product $product)
    {
        return $this->products->find($product->id);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'brand_name' => ['nullable', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:draft,active,inactive'],
        ]);

        return $this->products->update($product, $data);
    }

    public function destroy(Product $product)
    {
        $this->products->delete($product);

        return response()->noContent();
    }

    /** Builds the combination table rows for the UI. Saves nothing. */
    public function combinations(Request $request)
    {
        $data = $request->validate([
            'attributes' => ['nullable', 'array'],
            'attributes.*.attribute_id' => ['required', 'integer'],
            'attributes.*.value_ids' => ['required', 'array', 'min:1'],
            'attributes.*.value_ids.*' => ['integer'],
        ]);

        return ['combinations' => $this->variants->preview($data['attributes'] ?? [])];
    }

    public function addVariants(Request $request, Product $product)
    {
        $data = $request->validate([
            'variants' => ['required', 'array', 'min:1', 'max:500'],
            ...StoreProductRequest::variantRules(),
        ]);

        $this->variants->add($product, $data['variants']);

        return response()->json($this->products->find($product->id), 201);
    }

    public function updateVariant(Request $request, ProductVariant $variant)
    {
        $data = $request->validate([
            'sku' => ['sometimes', 'string', 'max:100'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        return $this->variants->update($variant, $data);
    }

    public function destroyVariant(ProductVariant $variant)
    {
        $this->variants->remove($variant);

        return response()->noContent();
    }

    public function addImages(Request $request, Product $product)
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1'],
            ...StoreProductRequest::imageRules(),
        ]);

        $this->images->add($product, $data['images']);

        return response()->json($this->products->find($product->id), 201);
    }

    public function destroyImage(ProductImage $image)
    {
        $this->images->delete($image);

        return response()->noContent();
    }
}
