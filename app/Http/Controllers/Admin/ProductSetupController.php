<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Brand;
use App\Services\AttributeService;
use Illuminate\Http\Request;

/** Data the admin "create product" form needs before submitting. */
class ProductSetupController extends Controller
{
    public function __construct(private AttributeService $attributes) {}

    /** Attributes (with predefined values) of the chosen category. */
    public function categoryAttributes(int $category)
    {
        return $this->attributes->forCategory($category);
    }

    /** "+ add value" button: saves a new value and returns it (or the existing one). */
    public function addValue(Request $request, Attribute $attribute)
    {
        $data = $request->validate(['value' => ['required', 'string', 'max:100']]);

        return response()->json($this->attributes->addValue($attribute, $data['value']), 201);
    }

    /** Brand dropdown. Admin may also type a new name in brand_name when saving the product. */
    public function brands()
    {
        return Brand::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}
