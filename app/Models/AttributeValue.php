<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeValue extends Model
{
    protected $fillable = [
        'attribute_id',
        'value',
        'slug',
        'sort_order',
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(
            ProductAttribute::class,
            'attribute_id'
        );
    }

    public function variantValues(): HasMany
    {
        return $this->hasMany(
            VariantAttributeValue::class,
            'attribute_value_id'
        );
    }
}