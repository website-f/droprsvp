<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;

/** One user blocking another: neither can message the other while it stands. */
class Block extends Model
{
    protected $table = 'chat_blocks';

    protected $fillable = ['blocker_id', 'blocked_id'];
}
