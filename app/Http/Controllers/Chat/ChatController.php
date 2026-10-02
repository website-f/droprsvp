<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\Chat\Report;
use App\Models\Chat\UserState;
use App\Models\Order;
use App\Models\User;
use App\Services\Chat\Messenger;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\PollToken;
use App\Support\Chat\Realtime;
use App\Support\RolePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Messages: the full-screen inbox and conversation, and every action in it.
 * Live updates come from ChatPollController, which is session-free.
 */
class ChatController extends Controller
{
    public function __construct(private Messenger $messenger) {}

    public function index(Request $request)
    {
        return $this->page($request);
    }

    public function show(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->involves($request->user()->id), 404);

        return $this->page($request, $conversation);
    }

    /** "Message" buttons land here: the existing thread, or a blank one to start. */
    public function start(Request $request, User $user): RedirectResponse|Response
    {
        $me = $request->user();

        if ($user->id === $me->id) {
            return redirect('/messages');
        }

        if ($c = Messenger::between($me->id, $user->id)) {
            return redirect('/messages/'.$c->id);
        }

        return $this->page($request, null, $user);
    }

    private function page(Request $request, ?Conversation $selected = null, ?User $draftTo = null)
    {
        $me = $request->user();

        if ($selected) {
            $this->messenger->markRead($me, $selected);
        }

        return Inertia::render('chat/index', [
            'me' => ['id' => $me->id, 'name' => $me->name, 'admin' => RolePermissions::isAdmin($me)],
            'inbox' => Messenger::inbox($me->id),
            'counts' => [
                'unread' => Messenger::unreadTotal($me->id),
                'requests' => Messenger::requestCount($me->id),
                'announcements' => Messenger::unreadBroadcasts($me),
            ],
            'selected' => $selected ? [
                ...Messenger::header($selected, $me->id),
                'messages' => Messenger::messages($selected, $me->id),
                'typing' => Realtime::isOtherTyping($selected->id, $me->id),
            ] : null,
            'draft' => $draftTo ? Messenger::person($draftTo) + [
                'blocked' => Messenger::blockedBetween($me->id, $draftTo->id),
                'request' => ! Messenger::related($me, $draftTo),
            ] : null,
            'realtime' => [
                'token' => PollToken::issue($me->id),
                'v' => Realtime::version($me->id),
                'bv' => Realtime::broadcastVersion(),
            ],
            'config' => [
                'max_length' => Messenger::MAX_LENGTH,
                'images' => (bool) ChatSettings::get('images_enabled'),
                'max_image_mb' => (int) ChatSettings::get('max_image_mb'),
                'request_limit' => (int) ChatSettings::get('request_message_limit'),
                'poll' => [
                    'active' => (int) ChatSettings::get('poll_active'),
                    'open' => (int) ChatSettings::get('poll_open'),
                    'inbox' => (int) ChatSettings::get('poll_inbox'),
                    'hidden' => (int) ChatSettings::get('poll_hidden'),
                ],
            ],
            'suspended' => UserState::find($me->id)?->isSuspended() ? (UserState::find($me->id)->suspended_reason ?: 'Your messaging is suspended.') : null,
            'reasons' => collect(Report::REASONS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    /** A fresh poll token when the old one expires (the page may stay open for days). */
    public function token(Request $request): JsonResponse
    {
        return response()->json(['token' => PollToken::issue($request->user()->id)]);
    }

    /** One conversation's header and latest messages, for opening it without a page load. */
    public function conversation(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->involves($me->id), 404);
        $this->messenger->markRead($me, $conversation);

        return response()->json([
            ...Messenger::header($conversation, $me->id),
            'messages' => Messenger::messages($conversation, $me->id),
            'typing' => Realtime::isOtherTyping($conversation->id, $me->id),
        ]);
    }

    /** Older messages, as the user scrolls up. */
    public function older(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->involves($request->user()->id), 404);

        return response()->json([
            'messages' => Messenger::messages($conversation, $request->user()->id, before: max(1, (int) $request->query('before'))),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'recipient_id' => ['nullable', 'integer'],
            'body' => ['nullable', 'string', 'max:'.(Messenger::MAX_LENGTH + 100)],
            'image' => ['nullable', 'file', 'max:'.((int) ChatSettings::get('max_image_mb') * 1024)],
        ]);

        $me = $request->user();
        $to = ! empty($data['conversation_id'])
            ? Conversation::findOrFail($data['conversation_id'])
            : User::findOrFail($data['recipient_id'] ?? 0);

        try {
            $message = $this->messenger->send($me, $to, $data['body'] ?? null, $request->file('image'), $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $conversation = $message->conversation;

        return response()->json([
            'message' => Messenger::payload($message, $me->id),
            'conversation' => Messenger::header($conversation, $me->id),
        ]);
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->involves($request->user()->id), 404);
        $this->messenger->markRead($request->user(), $conversation);

        return response()->json(['ok' => true]);
    }

    /** Typing indicator: a six-second note in the cache, nothing in the database. */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->involves($request->user()->id), 404);
        Realtime::typing($conversation->id, $request->user()->id);

        return response()->json(['ok' => true]);
    }

    public function accept(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->involves($request->user()->id), 404);
        $this->messenger->accept($request->user(), $conversation);

        return response()->json(Messenger::header($conversation->fresh(), $request->user()->id));
    }

    public function decline(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->involves($request->user()->id), 404);
        $this->messenger->decline($request->user(), $conversation);

        return response()->json(['ok' => true]);
    }

    public function hide(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->involves($request->user()->id), 404);
        $this->messenger->hide($request->user(), $conversation);

        return response()->json(['ok' => true]);
    }

    public function unsend(Request $request, Message $message): JsonResponse
    {
        try {
            $this->messenger->unsend($request->user(), $message);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(Messenger::payload($message->fresh(), $request->user()->id));
    }

    public function block(Request $request, User $user): JsonResponse
    {
        abort_if($user->id === $request->user()->id, 422);
        $this->messenger->block($request->user(), $user);

        return response()->json(['ok' => true]);
    }

    public function unblock(Request $request, User $user): JsonResponse
    {
        $this->messenger->unblock($request->user(), $user);

        return response()->json(['ok' => true]);
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'conversation_id' => ['nullable', 'integer'],
            'message_id' => ['nullable', 'integer'],
            'reason' => ['required', Rule::in(array_keys(Report::REASONS))],
            'details' => ['nullable', 'string', 'max:1000'],
            'block' => ['boolean'],
        ]);

        $me = $request->user();
        $reported = User::findOrFail($data['user_id']);
        $conversation = ! empty($data['conversation_id']) ? Conversation::find($data['conversation_id']) : null;
        $message = ! empty($data['message_id']) ? Message::find($data['message_id']) : null;

        // Only about a conversation you are in, and a message from the person reported.
        abort_if($conversation && ! $conversation->involves($me->id), 404);
        abort_if($message && ((int) $message->sender_id !== $reported->id || ! $message->conversation->involves($me->id)), 404);

        $this->messenger->report($me, $reported, $data['reason'], $data['details'] ?? null, $conversation ?? $message?->conversation, $message);

        if (! empty($data['block'])) {
            $this->messenger->block($me, $reported);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * People to start a conversation with. Never a directory of every user:
     * organizers (public anyway), people you follow or who follow you, and
     * people you have bought from or who bought from you. Admins can find anyone.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $me = $request->user();

        if (mb_strlen($q) < 2) {
            return response()->json(['people' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $users = User::query()
            ->whereKeyNot($me->id)
            ->whereNull('disabled_at')
            ->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhereHas('organizerProfile', fn ($p) => $p->where('business_name', 'like', $like)))
            ->when(! RolePermissions::isAdmin($me), fn ($query) => $query->where(fn ($w) => $w
                ->whereHas('organizerProfile', fn ($p) => $p->where('status', 'approved'))
                ->orWhereIn('id', $me->following()->select('users.id'))
                ->orWhereIn('id', $me->followers()->select('users.id'))
                ->orWhereIn('id', Order::query()->whereNotNull('paid_at')->whereNotNull('user_id')
                    ->whereHas('event', fn ($e) => $e->where('user_id', $me->id))->select('user_id'))))
            ->limit(12)
            ->get(['id', 'name', 'avatar', 'slug']);

        return response()->json(['people' => $users->map(fn (User $u) => Messenger::person($u->loadMissing('organizerProfile')))->values()]);
    }

    /** The DropRSVP announcements thread. Opening it marks everything read. */
    public function announcements(Request $request): JsonResponse
    {
        $me = $request->user();
        $items = Messenger::broadcastsFor($me);

        if ($items->isNotEmpty()) {
            UserState::for($me->id)->forceFill(['broadcast_read_id' => max((int) $items->max('id'), (int) UserState::for($me->id)->broadcast_read_id)])->save();
        }

        return response()->json(['items' => $items->map(fn ($b) => [
            'id' => $b->id,
            'title' => $b->title,
            'body' => $b->body,
            'at' => $b->created_at->toIso8601String(),
        ])->values()]);
    }
}
