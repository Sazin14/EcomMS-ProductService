<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checks the SHAPE of the payload (fields present, numbers are numbers).
 * Business rules (category/value ownership, duplicate combinations) live in the services.
 */
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // access is enforced by the auth.jwt, authz and permission:* middleware on the routes
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'brand_name' => ['nullable', 'string', 'max:100'],   // new brand typed by admin
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,active,inactive'],

            // [{attribute_id: 1, value_ids: [1, 2]}, ...]  (omit for a simple product)
            'attributes' => ['nullable', 'array'],
            'attributes.*.attribute_id' => ['required', 'integer'],
            'attributes.*.value_ids' => ['required', 'array', 'min:1'],
            'attributes.*.value_ids.*' => ['integer'],

            // Fixed (non-variant) attributes, e.g. [{attribute_id: 5, value_ids: [12], values: ["Cotton"]}]  (optional)
            'specs' => ['nullable', 'array'],
            ...self::specRules(),

            'variants' => ['required', 'array', 'min:1', 'max:500'],
            ...self::variantRules('variants.*.'),

            'images' => ['nullable', 'array'],
            ...self::imageRules('images.*.'),
        ];
    }

    public static function specRules(string $p = 'specs.*.'): array
    {
        return [
            $p.'attribute_id' => ['required', 'integer'],
            $p.'value_ids' => ['nullable', 'array'],         // existing values
            $p.'value_ids.*' => ['integer'],
            $p.'values' => ['nullable', 'array'],            // typed values: reused if they exist, created otherwise
            $p.'values.*' => ['string', 'max:100'],
        ];
    }

    public static function variantRules(string $p = 'variants.*.'): array
    {
        return [
            $p.'attribute_value_ids' => ['present', 'array'],
            $p.'attribute_value_ids.*' => ['integer'],
            $p.'sku' => ['nullable', 'string', 'max:100'],   // blank = auto-generated
            $p.'purchase_price' => ['required', 'numeric', 'min:0'],
            $p.'selling_price' => ['required', 'numeric', 'min:0'],
            $p.'stock' => ['nullable', 'integer', 'min:0'],   // OPENING stock only: sent once to InventoryService; later changes happen there
            $p.'status' => ['nullable', 'in:active,inactive'],
            $p.'allow_preorder' => ['nullable', 'boolean'],   // can customers pre-order this variant when it is out of stock?
        ];
    }

    public static function imageRules(string $p = 'images.*.'): array
    {
        return [
            $p.'url' => ['required', 'string', 'max:2048', 'url:http,https'],   // no javascript:/data: URLs in an <img src>
            $p.'attribute_value_id' => ['nullable', 'integer'],   // null = general image
            $p.'sort_order' => ['nullable', 'integer', 'min:0'],
            $p.'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
