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

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'product_attributes')
            ->withPivot('sort_order', 'is_variant')
            ->orderBy('product_attributes.sort_order');
    }

    public function variantAttributes(): BelongsToMany
    {
        return $this->attributes()->wherePivot('is_variant', true);
    }

    public function specAttributes(): BelongsToMany
    {
        return $this->attributes()->wherePivot('is_variant', false);
    }

    public function specGroups(): array
    {
        $this->loadMissing('attributes', 'attributeValues');

        return $this->getRelation('attributes')
            ->filter(fn ($a) => ! (bool) $a->pivot->is_variant)
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
                'values' => $this->attributeValues
                    ->where('attribute_id', $a->id)
                    ->sortBy('sort_order')
                    ->map(fn ($v) => ['id' => $v->id, 'value' => $v->value, 'slug' => $v->slug])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

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

    public function thumbnail(): HasOne
    {
        return $this->hasOne(ProductImage::class)
            ->where('is_primary', true)
            ->orderByRaw('attribute_value_id is not null')
            ->orderBy('sort_order');
    }

    public function imagesFor(ProductVariant $variant): Collection
    {
        $valueIds = $variant->attributeValues->pluck('id');

        return $this->images
            ->filter(fn (ProductImage $img) => $img->attribute_value_id === null
                || $valueIds->contains($img->attribute_value_id))
            ->values();
    }
}
