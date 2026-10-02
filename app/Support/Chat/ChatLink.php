<?php

namespace App\Support\Chat;

use App\Models\User;

/**
 * "Message" buttons next to people on the site: an event's participants, an
 * organizer's members, the authors in a discussion.
 *
 * Those pages show people the viewer has no other connection to, so the link
 * carries a key signed for this viewer and this person. Opening a chat with a
 * stranger needs that key; without it, /messages/new/{id} would let anyone
 * walk every user id, read the names, and message them cold. The key cannot
 * be reused by someone else, and it only ever opens a request (limited,
 * blockable, reportable), never a full conversation.
 */
final class ChatLink
{
    /** The button's URL for $viewer to message $targetId, or null when there should be no button. */
    public static function for(?User $viewer, ?int $targetId): ?string
    {
        if (! $viewer || ! $targetId || $targetId === $viewer->id) {
            return null;
        }

        return '/messages/new/'.$targetId.'?k='.self::key($viewer->id, $targetId);
    }

    public static function key(int $from, int $to): string
    {
        return substr(hash_hmac('sha256', "chat-link|{$from}|{$to}", (string) config('app.key')), 0, 32);
    }

    public static function valid(int $from, int $to, mixed $key): bool
    {
        return is_string($key) && hash_equals(self::key($from, $to), $key);
    }
}
