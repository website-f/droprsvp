<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One side's view of a conversation: read position, unread count, hidden. */
class Participant extends Model
{
    protected $table = 'chat_participants';

    protected $fillable = ['conversation_id', 'user_id', 'other_user_id', 'last_read_message_id', 'unread_count', 'hidden_at'];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function other(): BelongsTo
    {
        return $this->belongsTo(User::class, 'other_user_id');
    }
}
