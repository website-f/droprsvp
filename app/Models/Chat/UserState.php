<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;

/** A user's chat standing and bookkeeping. One row per user who has used chat. */
class UserState extends Model
{
    protected $table = 'chat_user_states';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'suspended_until', 'suspended_forever', 'suspended_reason', 'broadcast_read_id', 'notified_upto_message_id', 'last_notified_at'];

    protected function casts(): array
    {
        return ['suspended_until' => 'datetime', 'suspended_forever' => 'boolean', 'last_notified_at' => 'datetime'];
    }

    public static function for(int $userId): self
    {
        return self::firstOrCreate(['user_id' => $userId]);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_forever || ($this->suspended_until !== null && $this->suspended_until->isFuture());
    }
}
