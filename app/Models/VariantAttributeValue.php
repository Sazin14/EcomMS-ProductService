<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariantAttributeValue extends Model
{
    protected $fillable = [
        'variant_id',
        'attribute_id',
        'attribute_value_id',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(
            ProductVariant::class,
            'variant_id'
        );
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(
            ProductAttribute::class,
            'attribute_id'
        );
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(
            AttributeValue::class,
            'attribute_value_id'
        );
    }
}