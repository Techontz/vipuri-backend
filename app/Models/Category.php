<?php

namespace App\Models;

use App\Constants\Status;
use App\Traits\GlobalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use GlobalStatus;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'show_in_navbar' => 'boolean',
            'is_top' => 'boolean',
            'is_popular' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('position');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(Offer::class, 'offer_category');
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_category');
    }

    public function scopeIsParent($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeShowInNavbar($query)
    {
        return $query->where('show_in_navbar', Status::YES);
    }

    public function scopeTop($query)
    {
        return $query->where('is_top', Status::YES);
    }

    public function scopePopular($query)
    {
        return $query->where('is_popular', Status::YES);
    }

    /** All descendant category ids including this one. */
    public function descendantIds(): array
    {
        $ids = [$this->id];

        foreach ($this->subcategories as $child) {
            $ids = array_merge($ids, $child->descendantIds());
        }

        return $ids;
    }
}
