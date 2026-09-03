<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A collaborator an account owner has granted access to their events. The member
 * signs in with their own account and can manage the owner's events (but not the
 * owner's money, team or account).
 */
class TeamMember extends Model
{
    protected $fillable = ['owner_id', 'member_id', 'role'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }
}
