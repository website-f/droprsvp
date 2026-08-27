<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmsCategory extends Model
{
    protected $fillable = ['name', 'slug'];

    public function posts(): HasMany
    {
        return $this->hasMany(CmsPost::class, 'category_id');
    }
}
