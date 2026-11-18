<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Services\AttributeService;
use Illuminate\Http\Request;

class AttributeController extends Controller
{
    public function __construct(private AttributeService $attributes) {}

    public function index()
    {
        return ['data' => $this->attributes->all()];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['nullable', 'in:select,color,number'],
        ]);

        return response()->json($this->attributes->create($data), 201);
    }

    public function update(Request $request, Attribute $attribute)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'in:select,color,number'],
        ]);

        return $this->attributes->update($attribute, $data);
    }

    public function destroy(Attribute $attribute)
    {
        $this->attributes->delete($attribute);

        return response()->noContent();
    }

    public function updateValue(Request $request, AttributeValue $attributeValue)
    {
        $data = $request->validate([
            'value' => ['sometimes', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        return $this->attributes->updateValue($attributeValue, $data);
    }

    public function destroyValue(AttributeValue $attributeValue)
    {
        $this->attributes->deleteValue($attributeValue);

        return response()->noContent();
    }
}
