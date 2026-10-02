<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One chat message: text and/or one image. */
class Message extends Model
{
    protected $table = 'chat_messages';

    protected $fillable = ['conversation_id', 'sender_id', 'body', 'image_path', 'image_meta', 'ip', 'deleted_at', 'removed_by_admin'];

    protected $hidden = ['ip', 'image_path'];

    protected function casts(): array
    {
        return ['image_meta' => 'array', 'deleted_at' => 'datetime', 'removed_by_admin' => 'boolean'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
