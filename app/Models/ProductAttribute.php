<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductAttribute extends Model
{
    protected $table = 'attributes';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class, 'attribute_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'category_attributes',
            'attribute_id',
            'category_id'
        )->withPivot([
            'is_required',
            'is_variant',
            'sort_order',
        ])->withTimestamps();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_attributes',
            'attribute_id',
            'product_id'
        )->withPivot([
            'sort_order',
        ])->withTimestamps();
    }
}