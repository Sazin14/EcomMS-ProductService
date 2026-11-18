<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['parent_id', 'name', 'slug', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attributes')
            ->withPivot(['is_variant', 'sort_order'])
            ->orderBy('category_attributes.sort_order');
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_category');
    }

    public static function subtreeIds(int $rootId, bool $onlyActive = false): array
    {
        $ids = [$rootId];
        $level = [$rootId];

        while ($level) {
            $level = static::whereIn('parent_id', $level)
                ->when($onlyActive, fn ($q) => $q->where('is_active', true))
                ->pluck('id')->all();
            $ids = array_merge($ids, $level);
        }

        return $ids;
    }

    public function descendantIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];

        while ($level) {
            $level = static::whereIn('parent_id', $level)->where('is_active', true)->pluck('id')->all();
            $ids = array_merge($ids, $level);
        }

        return $ids;
    }
}
