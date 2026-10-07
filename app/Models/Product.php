<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = ['category_id', 'brand_id', 'name', 'slug', 'description', 'status'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** Attributes this product uses (RAM, Storage, Color). */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'product_attributes')
            ->withPivot('sort_order')
            ->orderBy('product_attributes.sort_order');
    }

    /** Values the admin selected for this product (8GB, 16GB, Space Grey...). */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'product_attribute_assignments');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Card image for listings: a primary image, general ones first. */
    public function thumbnail(): HasOne
    {
        return $this->hasOne(ProductImage::class)
            ->where('is_primary', true)
            ->orderByRaw('attribute_value_id is not null')
            ->orderBy('sort_order');
    }

    /** Images to show for one variant: its own attribute-value images plus general ones. */
    public function imagesFor(ProductVariant $variant): Collection
    {
        $valueIds = $variant->attributeValues->pluck('id');

        return $this->images
            ->filter(fn (ProductImage $img) => $img->attribute_value_id === null
                || $valueIds->contains($img->attribute_value_id))
            ->values();
    }
}
