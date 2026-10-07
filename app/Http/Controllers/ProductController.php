<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Public
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): JsonResponse
    {
        $products = $this->productService->list(
            $request->all()
        );

        return response()->json($products);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(
            $this->productService->find($product->id)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Staff Protected
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'category_id' => [
                'required',
                'integer',
                'exists:categories,id',
            ],

            'brand_id' => [
                'nullable',
                'integer',
                'exists:brands,id',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'status' => [
                'nullable',
                Rule::in([
                    'draft',
                    'active',
                    'inactive',
                ]),
            ],

            /*
            |--------------------------------------------------------------------------
            | Product Attributes
            |--------------------------------------------------------------------------
            */

            'attributes' => [
                'required',
                'array',
                'min:1',
            ],

            'attributes.*.attribute_id' => [
                'required',
                'integer',
                'exists:attributes,id',
            ],

            'attributes.*.values' => [
                'required',
                'array',
                'min:1',
            ],

            'attributes.*.values.*.id' => [
                'nullable',
                'integer',
                'exists:attribute_values,id',
            ],

            'attributes.*.values.*.value' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $result = $this->productService->create(
            $validated
        );

        return response()->json(
            $result,
            201
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Create Actual Variants
    |--------------------------------------------------------------------------
    */

    public function storeVariants(
        Request $request,
        Product $product
    ): JsonResponse {

        $validated = $request->validate([
            'variants' => [
                'required',
                'array',
                'min:1',
            ],

            'variants.*.attribute_values' => [
                'required',
                'array',
                'min:1',
            ],

            'variants.*.attribute_values.*' => [
                'required',
                'integer',
                'exists:attribute_values,id',
            ],

            'variants.*.sku' => [
                'required',
                'string',
                'max:255',
                'distinct',
                'unique:product_variants,sku',
            ],

            'variants.*.purchase_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'variants.*.selling_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'variants.*.status' => [
                'nullable',
                Rule::in([
                    'active',
                    'inactive',
                ]),
            ],
        ]);

        $product = $this->productService->createVariants(
            product: $product,
            variants: $validated['variants']
        );

        return response()->json([
            'message' => 'Product variants created successfully.',
            'product' => $product,
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        Product $product
    ): JsonResponse {

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'category_id' => [
                'sometimes',
                'integer',
                'exists:categories,id',
            ],

            'brand_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:brands,id',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'draft',
                    'active',
                    'inactive',
                ]),
            ],
        ]);

        $product = $this->productService->update(
            product: $product,
            data: $validated
        );

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Product $product
    ): JsonResponse {

        $this->productService->delete(
            $product
        );

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Category Attributes
    |--------------------------------------------------------------------------
    */

    public function categoryAttributes(
        int $category
    ): JsonResponse {

        return response()->json(
            $this->productService
                ->getCategoryAttributes($category)
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Images
    |--------------------------------------------------------------------------
    */

    public function storeImages(
        Request $request,
        Product $product
    ): JsonResponse {

        $validated = $request->validate([
            'images' => [
                'required',
                'array',
                'min:1',
            ],

            'images.*.attribute_value_id' => [
                'nullable',
                'integer',
                'exists:attribute_values,id',
            ],

            'images.*.url' => [
                'required',
                'string',
                'max:2048',
            ],

            'images.*.sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'images.*.is_primary' => [
                'nullable',
                'boolean',
            ],
        ]);

        $this->productService->addImages(
            product: $product,
            images: $validated['images']
        );

        return response()->json([
            'message' => 'Product images added successfully.',
            'product' => $this->productService->find(
                $product->id
            ),
        ], 201);
    }
}