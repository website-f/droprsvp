<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;

/** A banned IP address: permanent (until = null) or until a time. */
class IpBan extends Model
{
    protected $table = 'chat_ip_bans';

    protected $fillable = ['ip', 'reason', 'until', 'automatic', 'created_by'];

    protected function casts(): array
    {
        return ['until' => 'datetime', 'automatic' => 'boolean'];
    }

    public function isActive(): bool
    {
        return $this->until === null || $this->until->isFuture();
    }
}
