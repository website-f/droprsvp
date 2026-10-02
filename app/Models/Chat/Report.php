<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's report about another user, optionally about one message. */
class Report extends Model
{
    public const REASONS = [
        'spam' => 'Spam or scam',
        'harassment' => 'Harassment or bullying',
        'inappropriate' => 'Inappropriate content',
        'impersonation' => 'Pretending to be someone else',
        'other' => 'Something else',
    ];

    protected $table = 'chat_reports';

    protected $attributes = ['status' => 'open'];

    protected $fillable = ['reporter_id', 'reported_user_id', 'conversation_id', 'message_id', 'reason', 'details', 'status', 'action', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reported(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
