<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizerPost extends Model
{
    protected $fillable = ['organizer_id', 'user_id', 'parent_id', 'body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Direct children (one level), oldest first, with their author loaded. */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest()->with('author:id,name');
    }

    /**
     * The whole reply subtree eager-loaded in a single walk — each reply pulls its
     * own author and its own nested replies, so a Facebook-style chain of any depth
     * comes back in one query per level instead of N+1 per comment.
     */
    public function repliesRecursive(): HasMany
    {
        return $this->replies()->with('repliesRecursive');
    }
}
