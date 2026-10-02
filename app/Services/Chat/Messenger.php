<?php

namespace App\Services\Chat;

use App\Models\Chat\Block;
use App\Models\Chat\Broadcast;
use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\Chat\Participant;
use App\Models\Chat\Report;
use App\Models\Chat\UserState;
use App\Models\Order;
use App\Models\User;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\Realtime;
use App\Support\RolePermissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The rules of chat, in one place.
 *
 * Anyone may message anyone — but a first message from a stranger arrives as
 * a REQUEST: the recipient sees it under Requests and chooses to accept,
 * delete or block. Until then the sender may send only a few messages, and no
 * images. Not a stranger: someone the recipient follows, an organizer and a
 * person who bought tickets from them (either way round), or DropRSVP staff.
 *
 * On top: blocks (either way, silently final), suspensions, a per-minute
 * message limit, a daily limit on starting conversations (tighter for brand-new
 * accounts), and a duplicate check that stops the same text going to many
 * people at once — the signature of spam.
 */
class Messenger
{
    public const MAX_LENGTH = 2000;

    public function __construct(private Attachments $attachments) {}

    // ---- finding and starting ------------------------------------------------

    public static function between(int $a, int $b): ?Conversation
    {
        return Conversation::where('user_low_id', min($a, $b))->where('user_high_id', max($a, $b))->first();
    }

    /** Do these two already know each other well enough to skip the request step? */
    public static function related(User $sender, User $recipient): bool
    {
        if (RolePermissions::isAdmin($sender)) {
            return true;
        }

        // The recipient follows the sender (an organizer they chose to follow).
        if ($recipient->following()->whereKey($sender->id)->exists()) {
            return true;
        }

        // A buyer and the organizer they bought from, either way round.
        $bought = fn (User $buyer, User $organizer) => Order::query()
            ->whereNotNull('paid_at')
            ->where(fn ($q) => $q->where('user_id', $buyer->id)->orWhereRaw('LOWER(buyer_email) = ?', [strtolower((string) $buyer->email)]))
            ->whereHas('event', fn ($e) => $e->where('user_id', $organizer->id))
            ->exists();

        return $bought($sender, $recipient) || $bought($recipient, $sender);
    }

    // ---- sending -----------------------------------------------------------------

    /**
     * Send a message: to an existing conversation, or to a user (starting one).
     *
     * @throws RuntimeException with a message fit to show the sender
     */
    public function send(User $sender, Conversation|User $to, ?string $body, ?UploadedFile $image = null, ?string $ip = null): Message
    {
        if (! ChatSettings::get('enabled')) {
            throw new RuntimeException('Messaging is switched off for now.');
        }

        $this->assertNotSuspended($sender);

        $body = trim(str_replace("\r\n", "\n", (string) $body));
        $body = $body === '' ? null : $body;

        if ($body === null && ! $image) {
            throw new RuntimeException('Write a message or add an image.');
        }

        if ($body !== null && mb_strlen($body) > self::MAX_LENGTH) {
            throw new RuntimeException('Messages are limited to '.number_format(self::MAX_LENGTH).' characters.');
        }

        if ($image && ! ChatSettings::get('images_enabled')) {
            throw new RuntimeException('Sending images is switched off for now.');
        }

        $recipient = $to instanceof User ? $to : User::findOrFail($to->otherId($sender->id));
        $conversation = $to instanceof Conversation ? $to : self::between($sender->id, $recipient->id);

        if ($conversation && ! $conversation->involves($sender->id)) {
            throw new RuntimeException('Conversation not found.');
        }

        if ($recipient->id === $sender->id) {
            throw new RuntimeException('You cannot message yourself.');
        }

        if (self::blockedBetween($sender->id, $recipient->id) || $recipient->isDisabled()) {
            throw new RuntimeException('You can’t message this person.');
        }

        $this->assertRateLimits($sender, $conversation === null, $body);

        $conversation ??= $this->open($sender, $recipient);

        // Standing of the conversation for this sender.
        if ($conversation->status === 'declined' && (int) $conversation->requested_by === $sender->id) {
            throw new RuntimeException('You can’t send more messages until they reply.');
        }

        if ($conversation->status === 'request') {
            if ((int) $conversation->requested_by === $sender->id) {
                $sent = $conversation->messages()->where('sender_id', $sender->id)->count();
                $limit = (int) ChatSettings::get('request_message_limit');

                if ($sent >= $limit) {
                    throw new RuntimeException("Your message request is waiting. You can send more once {$recipient->name} accepts.");
                }

                if ($image) {
                    throw new RuntimeException('Images can be sent once they accept your message request.');
                }
            } else {
                // Replying to a request accepts it.
                $conversation->forceFill(['status' => 'active', 'accepted_at' => now()])->save();
            }
        }

        $stored = $image ? $this->attachments->store($image) : null;

        $message = DB::transaction(function () use ($conversation, $sender, $recipient, $body, $stored, $ip) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'image_path' => $stored['path'] ?? null,
                'image_meta' => $stored ? array_diff_key($stored, ['path' => true]) : null,
                'ip' => $ip,
            ]);

            $conversation->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();

            // The sender has read their own message; the recipient has one more unread.
            Participant::where('conversation_id', $conversation->id)->where('user_id', $sender->id)
                ->update(['last_read_message_id' => $message->id, 'hidden_at' => null]);
            Participant::where('conversation_id', $conversation->id)->where('user_id', $recipient->id)
                ->update(['unread_count' => DB::raw('unread_count + 1'), 'hidden_at' => null]);

            return $message;
        });

        Realtime::stopTyping($conversation->id);
        Realtime::bump($sender->id, $recipient->id);

        return $message;
    }

    /** Create the conversation (and both participant rows) on a first message. */
    private function open(User $sender, User $recipient): Conversation
    {
        return DB::transaction(function () use ($sender, $recipient) {
            $existing = self::between($sender->id, $recipient->id);
            if ($existing) {
                return $existing;
            }

            $related = self::related($sender, $recipient);

            $c = Conversation::create([
                'user_low_id' => min($sender->id, $recipient->id),
                'user_high_id' => max($sender->id, $recipient->id),
                'status' => $related ? 'active' : 'request',
                'requested_by' => $sender->id,
                'accepted_at' => $related ? now() : null,
            ]);

            foreach ([[$sender->id, $recipient->id], [$recipient->id, $sender->id]] as [$me, $other]) {
                Participant::create(['conversation_id' => $c->id, 'user_id' => $me, 'other_user_id' => $other]);
            }

            return $c;
        });
    }

    private function assertNotSuspended(User $user): void
    {
        $state = UserState::find($user->id);

        if ($state?->isSuspended()) {
            throw new RuntimeException('Your messaging is suspended'.($state->suspended_forever ? '' : ' until '.$state->suspended_until?->format('j M Y, g:ia')).'. '.($state->suspended_reason ?: ''));
        }
    }

    private function assertRateLimits(User $sender, bool $starting, ?string $body): void
    {
        $s = ChatSettings::all();

        $lastMinute = Message::where('sender_id', $sender->id)->where('created_at', '>=', now()->subMinute())->count();
        if ($lastMinute >= $s['messages_per_minute']) {
            throw new RuntimeException('You are sending messages too quickly. Wait a moment.');
        }

        if ($starting && ! RolePermissions::isAdmin($sender)) {
            $started = Conversation::where('requested_by', $sender->id)->where('created_at', '>=', now()->subDay())->count();
            $limit = $sender->created_at && $sender->created_at->gt(now()->subDay())
                ? $s['new_account_conversations_per_day']
                : $s['new_conversations_per_day'];

            if ($started >= $limit) {
                throw new RuntimeException('You have started as many new conversations as allowed today. Try again tomorrow.');
            }
        }

        // The same words to many different people in ten minutes is spam.
        if ($body !== null && mb_strlen($body) >= 12 && ! RolePermissions::isAdmin($sender)) {
            $copies = Message::where('sender_id', $sender->id)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->where('body', $body)
                ->distinct()
                ->count('conversation_id');

            if ($copies >= $s['duplicate_limit'] - 1) {
                throw new RuntimeException('This looks like the same message going to many people, so it was not sent.');
            }
        }
    }

    // ---- reading, requests, hiding ---------------------------------------------

    /** Mark everything up to now read, and tell the sender (read receipts). */
    public function markRead(User $user, Conversation $conversation): bool
    {
        $last = (int) $conversation->last_message_id;
        $p = Participant::where('conversation_id', $conversation->id)->where('user_id', $user->id)->first();

        if (! $p || ($p->last_read_message_id >= $last && $p->unread_count === 0)) {
            return false;
        }

        $p->forceFill(['last_read_message_id' => $last, 'unread_count' => 0])->save();
        Realtime::bump($user->id, $conversation->otherId($user->id));

        return true;
    }

    public function accept(User $user, Conversation $c): void
    {
        if ($c->isRequestFor($user->id) || ($c->status === 'declined' && (int) $c->requested_by !== $user->id)) {
            $c->forceFill(['status' => 'active', 'accepted_at' => now()])->save();
            Realtime::bump($user->id, $c->otherId($user->id));
        }
    }

    /** Decline a request: it leaves their inbox; the sender cannot send more. */
    public function decline(User $user, Conversation $c): void
    {
        if ($c->isRequestFor($user->id)) {
            $c->forceFill(['status' => 'declined'])->save();
            Participant::where('conversation_id', $c->id)->where('user_id', $user->id)->update(['hidden_at' => now(), 'unread_count' => 0]);
            Realtime::bump($user->id);
        }
    }

    /** Remove from my inbox; it comes back if a new message arrives. */
    public function hide(User $user, Conversation $c): void
    {
        Participant::where('conversation_id', $c->id)->where('user_id', $user->id)
            ->update(['hidden_at' => now(), 'unread_count' => 0, 'last_read_message_id' => (int) $c->last_message_id]);
        Realtime::bump($user->id);
    }

    /** Unsend: the sender removes their own message (within a day). */
    public function unsend(User $user, Message $m): void
    {
        if ((int) $m->sender_id !== $user->id) {
            throw new RuntimeException('You can only remove your own messages.');
        }

        if ($m->created_at->lt(now()->subDay())) {
            throw new RuntimeException('Messages can be removed for up to a day after sending.');
        }

        $this->remove($m, false);
    }

    /** Remove a message for everyone (sender or moderator). The image goes too. */
    public function remove(Message $m, bool $byAdmin): void
    {
        $this->attachments->delete($m->image_path);
        $m->forceFill(['deleted_at' => now(), 'removed_by_admin' => $byAdmin, 'body' => null, 'image_path' => null, 'image_meta' => null])->save();

        $c = $m->conversation;
        Realtime::bump((int) $c->user_low_id, (int) $c->user_high_id);
    }

    // ---- safety ----------------------------------------------------------------

    public static function blockedBetween(int $a, int $b): bool
    {
        return Block::where(fn ($q) => $q->where('blocker_id', $a)->where('blocked_id', $b))
            ->orWhere(fn ($q) => $q->where('blocker_id', $b)->where('blocked_id', $a))
            ->exists();
    }

    public function block(User $user, User $other): void
    {
        Block::firstOrCreate(['blocker_id' => $user->id, 'blocked_id' => $other->id]);

        if ($c = self::between($user->id, $other->id)) {
            Participant::where('conversation_id', $c->id)->where('user_id', $user->id)->update(['hidden_at' => now(), 'unread_count' => 0]);
        }

        Realtime::bump($user->id, $other->id);
    }

    public function unblock(User $user, User $other): void
    {
        Block::where('blocker_id', $user->id)->where('blocked_id', $other->id)->delete();
        Realtime::bump($user->id, $other->id);
    }

    public function report(User $reporter, User $reported, string $reason, ?string $details, ?Conversation $c, ?Message $m): Report
    {
        // One open report per reporter about the same person is enough.
        $open = Report::where('reporter_id', $reporter->id)->where('reported_user_id', $reported->id)->where('status', 'open')->first();

        return $open ?? Report::create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'conversation_id' => $c?->id,
            'message_id' => $m?->id,
            'reason' => $reason,
            'details' => $details ? mb_substr($details, 0, 1000) : null,
        ]);
    }

    // ---- reading for the UI ------------------------------------------------------

    /** Total unread across accepted conversations — the badge. Requests count separately. */
    public static function unreadTotal(int $userId): int
    {
        return (int) Participant::query()
            ->where('chat_participants.user_id', $userId)
            ->whereNull('chat_participants.hidden_at')
            ->where('unread_count', '>', 0)
            ->join('chat_conversations', 'chat_conversations.id', '=', 'chat_participants.conversation_id')
            ->where(fn ($q) => $q->where('chat_conversations.status', 'active')
                ->orWhere('chat_conversations.requested_by', $userId))
            ->sum('unread_count');
    }

    public static function requestCount(int $userId): int
    {
        return Participant::query()
            ->where('chat_participants.user_id', $userId)
            ->whereNull('chat_participants.hidden_at')
            ->join('chat_conversations', 'chat_conversations.id', '=', 'chat_participants.conversation_id')
            ->where('chat_conversations.status', 'request')
            ->where('chat_conversations.requested_by', '!=', $userId)
            ->count();
    }

    /**
     * The inbox: conversations newest first, each with the other person, the
     * last message and the unread count. One query for the list, one for users.
     *
     * @return list<array<string, mixed>>
     */
    public static function inbox(int $userId, int $limit = 60): array
    {
        $rows = Participant::query()
            ->where('chat_participants.user_id', $userId)
            ->whereNull('chat_participants.hidden_at')
            ->join('chat_conversations', 'chat_conversations.id', '=', 'chat_participants.conversation_id')
            ->whereNotNull('chat_conversations.last_message_id')
            ->orderByDesc('chat_conversations.last_message_at')
            ->limit($limit)
            ->get([
                'chat_participants.*',
                'chat_conversations.status', 'chat_conversations.requested_by',
                'chat_conversations.last_message_id', 'chat_conversations.last_message_at',
            ]);

        $messages = Message::whereIn('id', $rows->pluck('last_message_id'))->get()->keyBy('id');
        $users = self::people($rows->pluck('other_user_id')->all());
        $blocked = Block::where('blocker_id', $userId)->whereIn('blocked_id', $rows->pluck('other_user_id'))->pluck('blocked_id')->flip();

        return $rows->map(function ($r) use ($userId, $messages, $users, $blocked) {
            $m = $messages->get($r->last_message_id);

            return [
                'id' => (int) $r->conversation_id,
                'other' => $users[$r->other_user_id] ?? self::ghost((int) $r->other_user_id),
                'status' => $r->status,
                'request' => $r->status === 'request' && (int) $r->requested_by !== $userId,
                'pending' => $r->status !== 'active' && (int) $r->requested_by === $userId,
                'blocked' => $blocked->has($r->other_user_id),
                'unread' => (int) $r->unread_count,
                'last' => $m ? [
                    'text' => $m->deleted_at ? 'Message removed' : ($m->body ? Str::limit(preg_replace('/\s+/', ' ', $m->body), 80) : 'Photo'),
                    'image' => (bool) $m->image_path && ! $m->deleted_at,
                    'mine' => (int) $m->sender_id === $userId,
                    'at' => $m->created_at->toIso8601String(),
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Messages in a conversation, oldest first. After an id (new ones, for
     * polling) or before one (history, scrolling up).
     *
     * @return list<array<string, mixed>>
     */
    public static function messages(Conversation $c, int $viewerId, ?int $after = null, ?int $before = null, int $limit = 40): array
    {
        $query = Message::where('conversation_id', $c->id);

        if ($after) {
            $query->where('id', '>', $after)->orderBy('id')->limit(200);
            $rows = $query->get();
        } else {
            $query->when($before, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit($limit);
            $rows = $query->get()->reverse()->values();
        }

        return $rows->map(fn (Message $m) => self::payload($m, $viewerId))->all();
    }

    /** @return array<string, mixed> */
    public static function payload(Message $m, int $viewerId): array
    {
        $image = null;

        if ($m->image_path && ! $m->deleted_at) {
            $image = [
                'thumb' => route('chat.media', ['message' => $m->id, 'variant' => 'thumb'], false),
                'full' => route('chat.media', ['message' => $m->id, 'variant' => 'full'], false),
                'w' => (int) ($m->image_meta['width'] ?? 0),
                'h' => (int) ($m->image_meta['height'] ?? 0),
            ];
        }

        return [
            'id' => $m->id,
            'mine' => (int) $m->sender_id === $viewerId,
            'body' => $m->deleted_at ? null : $m->body,
            'image' => $image,
            'deleted' => (bool) $m->deleted_at,
            'removed_by_admin' => (bool) $m->removed_by_admin,
            'at' => $m->created_at->toIso8601String(),
        ];
    }

    /** What the conversation view needs about the other person and the thread's state. */
    public static function header(Conversation $c, int $viewerId): array
    {
        $otherId = $c->otherId($viewerId);
        $other = self::people([$otherId])[$otherId] ?? self::ghost($otherId);
        $theirs = Participant::where('conversation_id', $c->id)->where('user_id', $otherId)->first();
        $mine = Participant::where('conversation_id', $c->id)->where('user_id', $viewerId)->first();

        $requestLimit = (int) ChatSettings::get('request_message_limit');
        $sentWhilePending = $c->status !== 'active' && (int) $c->requested_by === $viewerId
            ? $c->messages()->where('sender_id', $viewerId)->count()
            : 0;

        return [
            'id' => $c->id,
            'other' => $other,
            'status' => $c->status,
            'request' => $c->isRequestFor($viewerId),
            'pending' => $c->status !== 'active' && (int) $c->requested_by === $viewerId,
            'pending_left' => max(0, $requestLimit - $sentWhilePending),
            'blocked_by_me' => Block::where('blocker_id', $viewerId)->where('blocked_id', $otherId)->exists(),
            'blocked_me' => Block::where('blocker_id', $otherId)->where('blocked_id', $viewerId)->exists(),
            // Read receipts: everything of mine up to here, they have seen.
            'their_read_id' => (int) ($theirs?->last_read_message_id ?? 0),
            'my_read_id' => (int) ($mine?->last_read_message_id ?? 0),
        ];
    }

    /**
     * People as the chat shows them — no email, ever.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function people(array $ids): array
    {
        return User::with('organizerProfile:id,user_id,business_name,poster,status')
            ->whereIn('id', array_unique($ids))
            ->get(['id', 'name', 'avatar', 'slug'])
            ->mapWithKeys(fn (User $u) => [$u->id => self::person($u)])
            ->all();
    }

    /** @return array<string, mixed> */
    public static function person(User $u): array
    {
        $profile = $u->organizerProfile;
        $seen = Realtime::lastSeen($u->id);

        return [
            'id' => $u->id,
            'name' => $profile?->business_name ?: $u->name,
            'avatar' => $profile?->poster ?: $u->avatar,
            'organizer' => $profile?->status === 'approved',
            'profile_url' => $profile && $u->slug ? '/en-my/o/'.$u->slug.'/' : null,
            'online' => $seen !== null && now()->getTimestamp() - $seen <= 90,
            'last_seen' => $seen ? date(DATE_ATOM, $seen) : null,
        ];
    }

    private static function ghost(int $id): array
    {
        return ['id' => $id, 'name' => 'Deleted account', 'avatar' => null, 'organizer' => false, 'profile_url' => null, 'online' => false, 'last_seen' => null];
    }

    // ---- announcements -----------------------------------------------------------

    /** Broadcasts this user is in the audience for, newest last. */
    public static function broadcastsFor(User $user, int $limit = 50)
    {
        return Broadcast::query()
            ->where(fn ($q) => $q->where('audience', 'all')
                ->when($user->hasRole('organizer'), fn ($w) => $w->orWhere('audience', 'organizers'))
                ->orWhere(fn ($w) => $w->where('audience', 'buyers')->whereRaw('? = 1', [self::isBuyer($user) ? 1 : 0])))
            ->where('created_at', '>=', $user->created_at ?? now()->subYears(10))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    public static function unreadBroadcasts(User $user): int
    {
        $readUpTo = (int) (UserState::find($user->id)?->broadcast_read_id ?? 0);

        // Nothing announced since they last looked: no need to work out the audience.
        if (! Broadcast::where('id', '>', $readUpTo)->exists()) {
            return 0;
        }

        return self::broadcastsFor($user)->where('id', '>', $readUpTo)->count();
    }

    private static function isBuyer(User $user): bool
    {
        return Cache::remember("chat:buyer:{$user->id}", 3600, fn () => Order::whereNotNull('paid_at')
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereRaw('LOWER(buyer_email) = ?', [strtolower((string) $user->email)]))
            ->exists());
    }
}
