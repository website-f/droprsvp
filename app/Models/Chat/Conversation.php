<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One-to-one conversation between two users. See App\Services\Chat\Messenger. */
class Conversation extends Model
{
    protected $table = 'chat_conversations';

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['user_low_id', 'user_high_id', 'status', 'requested_by', 'accepted_at', 'last_message_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'last_message_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'conversation_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function lowUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_low_id');
    }

    public function highUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_high_id');
    }

    public function involves(int $userId): bool
    {
        return (int) $this->user_low_id === $userId || (int) $this->user_high_id === $userId;
    }

    public function otherId(int $userId): int
    {
        return (int) $this->user_low_id === $userId ? (int) $this->user_high_id : (int) $this->user_low_id;
    }

    /** Pending for this user to accept (they did not start it). */
    public function isRequestFor(int $userId): bool
    {
        return $this->status === 'request' && (int) $this->requested_by !== $userId;
    }
}
