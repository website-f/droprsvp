<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\User;
use App\Services\Chat\Messenger;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\IpGuard;
use App\Support\Chat\PollToken;
use App\Support\Chat\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The live-update endpoint. Registered OUTSIDE the web middleware group: no
 * session, no cookies, no CSRF, no Inertia — a signed token instead (PollToken).
 *
 * The common case — nothing has changed — costs a token check and a few cache
 * reads, and answers in a few dozen bytes. Only when the user's version stamp
 * moved does it touch the database, and then only for what changed.
 *
 * Every answer says when to ask next ("n", seconds): fast while a conversation
 * is live, slower when it is quiet, slower still for a badge on another page,
 * and stretched for everyone when the site as a whole is busy. The browser
 * obeys it, so the server — not each tab — decides how hard it is polled.
 */
class ChatPollController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $userId = PollToken::verify($request->bearerToken() ?: $request->query('t'));

        if (! $userId) {
            return response()->json(['error' => 'token'], 401);
        }

        $s = ChatSettings::all();
        $ip = (string) $request->ip();

        // Per user and per address: one abusive tab, or one abusive network.
        if (RateLimiter::tooManyAttempts('chat-poll:u:'.$userId, $s['poll_limit_per_minute'])
            || RateLimiter::tooManyAttempts('chat-poll:ip:'.$ip, $s['poll_limit_per_minute'] * 4)) {
            IpGuard::strike($ip);

            return response()->json(['error' => 'slow down', 'n' => 30], 429, ['Retry-After' => 30]);
        }

        RateLimiter::hit('chat-poll:u:'.$userId, 60);
        RateLimiter::hit('chat-poll:ip:'.$ip, 60);

        $mode = in_array($request->query('mode'), ['badge', 'inbox', 'open'], true) ? $request->query('mode') : 'badge';
        $conversationId = $mode === 'open' ? (int) $request->query('c') : 0;
        $clientVersion = (int) $request->query('v');
        $clientBroadcast = (int) $request->query('bv');

        Realtime::seen($userId);
        $version = Realtime::version($userId);
        $broadcast = Realtime::broadcastVersion();
        $typing = $conversationId ? Realtime::isOtherTyping($conversationId, $userId) : false;

        $factor = Realtime::loadFactor($s['max_polls_per_second']);
        $next = fn (int $seconds) => (int) min(300, ceil($seconds * $factor));

        $base = ['v' => $version, 'bv' => $broadcast, 't' => $typing];

        // ---- nothing changed: the cheap path -----------------------------------
        if ($version === $clientVersion && $broadcast === $clientBroadcast && $version !== 0) {
            $interval = match ($mode) {
                'open' => $typing ? $s['poll_active'] : $s['poll_open'],
                'inbox' => $s['poll_inbox'],
                default => $s['poll_badge'],
            };

            return response()->json($base + ['n' => $next($interval)]);
        }

        // ---- something changed: fetch what the client needs ----------------------
        $user = User::find($userId);

        if (! $user || $user->isDisabled()) {
            return response()->json(['error' => 'token'], 401);
        }

        // A first poll after a cache clear has no version yet; give one.
        if ($version === 0) {
            Realtime::bump($userId);
            $base['v'] = Realtime::version($userId);
        }

        $payload = $base + [
            'counts' => [
                'unread' => Messenger::unreadTotal($userId),
                'requests' => Messenger::requestCount($userId),
                'announcements' => Messenger::unreadBroadcasts($user),
            ],
        ];

        $interval = $mode === 'badge' ? $s['poll_badge'] : $s['poll_inbox'];

        if ($mode !== 'badge') {
            $payload['inbox'] = Messenger::inbox($userId);
        }

        if ($conversationId && ($c = Conversation::find($conversationId)) && $c->involves($userId)) {
            // Seen it while looking at it: mark read, which tells the sender.
            if ($request->boolean('vis')) {
                app(Messenger::class)->markRead($user, $c);
                $payload['v'] = Realtime::version($userId);
            }

            $payload['open'] = Messenger::header($c, $userId) + [
                'messages' => Messenger::messages($c, $userId, after: max(0, (int) $request->query('after'))),
                // Unsent or moderated recently, so an open thread drops them live.
                'removed' => Message::where('conversation_id', $c->id)->whereNotNull('deleted_at')
                    ->where('deleted_at', '>=', now()->subDay())->pluck('id'),
            ];

            // Activity in the last two minutes: stay fast for a real-time feel.
            $interval = $c->last_message_at && $c->last_message_at->gt(now()->subMinutes(2)) ? $s['poll_active'] : $s['poll_open'];
        }

        return response()->json($payload + ['n' => $next($interval)]);
    }
}
