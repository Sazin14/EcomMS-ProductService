<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    protected $fillable = ['name', 'slug', 'type'];

    // No orderBy here on purpose: ordered relations break max()/count() on PostgreSQL.
    // Order where you load them: ->with(['values' => fn ($q) => $q->orderBy('sort_order')])
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_attributes');
    }
}
