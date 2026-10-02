<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;

/** An admin announcement, stored once and shown to everyone in its audience. */
class Broadcast extends Model
{
    public const AUDIENCES = ['all' => 'Everyone', 'organizers' => 'Organizers', 'buyers' => 'Ticket buyers'];

    protected $table = 'chat_broadcasts';

    protected $fillable = ['title', 'body', 'audience', 'recipients', 'sent_by'];
}
