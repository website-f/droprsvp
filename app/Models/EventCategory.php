<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventCategory extends Model
{
    protected $fillable = ['name', 'slug', 'icon', 'blurb', 'color', 'sort_order', 'content'];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'category_id');
    }
}
