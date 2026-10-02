<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chat\Broadcast;
use App\Models\Chat\Conversation;
use App\Models\Chat\IpBan;
use App\Models\Chat\Message;
use App\Models\Chat\Report;
use App\Models\Chat\UserState;
use App\Models\Order;
use App\Models\User;
use App\Services\Chat\Messenger;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\IpGuard;
use App\Support\Chat\Realtime;
use App\Support\Dates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Admin → Chat: reports, suspensions, IP bans, announcements and settings.
 *
 * Moderators read a conversation only through a report about it — there is
 * no "browse everyone's messages" screen, by design.
 */
class ChatModerationController extends Controller
{
    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['reports', 'users', 'ips', 'broadcasts', 'settings'], true) ? $request->query('tab') : 'reports';
        $status = in_array($request->query('status'), ['open', 'dismissed', 'actioned'], true) ? $request->query('status') : 'open';

        return Inertia::render('admin/chat/index', [
            'tab' => $tab,
            'stats' => [
                'messages_today' => Message::where('created_at', '>=', now()->startOfDay())->count(),
                'conversations_week' => Conversation::where('last_message_at', '>=', now()->subWeek())->count(),
                'open_reports' => Report::where('status', 'open')->count(),
                'suspended' => UserState::where(fn ($q) => $q->where('suspended_forever', true)->orWhere('suspended_until', '>', now()))->count(),
                'ip_bans' => IpBan::where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))->count(),
                'polls_per_second' => Realtime::currentLoad(),
            ],
            'reports' => $tab === 'reports' ? Report::where('status', $status)
                ->with(['reporter:id,name,email', 'reported:id,name,email', 'message:id,body,image_path,created_at,deleted_at'])
                ->latest('id')->paginate(20)->withQueryString()
                ->through(fn (Report $r) => [
                    'id' => $r->id,
                    'reason' => Report::REASONS[$r->reason] ?? $r->reason,
                    'details' => $r->details,
                    'status' => $r->status,
                    'action' => $r->action,
                    'reporter' => $r->reporter ? ['id' => $r->reporter->id, 'name' => $r->reporter->name, 'email' => $r->reporter->email] : null,
                    'reported' => $r->reported ? ['id' => $r->reported->id, 'name' => $r->reported->name, 'email' => $r->reported->email,
                        'reports' => Report::where('reported_user_id', $r->reported_user_id)->count(),
                        'suspended' => (bool) UserState::find($r->reported_user_id)?->isSuspended()] : null,
                    'message' => $r->message ? ['id' => $r->message->id, 'body' => $r->message->body, 'image' => (bool) $r->message->image_path, 'deleted' => (bool) $r->message->deleted_at] : null,
                    'conversation_id' => $r->conversation_id,
                    'when' => Dates::display($r->created_at, 'j M, g:ia'),
                ]) : null,
            'reportStatus' => $status,
            'suspended' => $tab === 'users' ? UserState::query()
                ->where(fn ($q) => $q->where('suspended_forever', true)->orWhere('suspended_until', '>', now()))
                ->join('users', 'users.id', '=', 'chat_user_states.user_id')
                ->get(['chat_user_states.*', 'users.name', 'users.email'])
                ->map(fn ($s) => [
                    'id' => $s->user_id, 'name' => $s->name, 'email' => $s->email, 'reason' => $s->suspended_reason,
                    'until' => $s->suspended_forever ? null : Dates::display($s->suspended_until, 'j M Y, g:ia'),
                ]) : null,
            'ipBans' => $tab === 'ips' ? IpBan::query()->latest('id')->limit(200)->get()->map(fn (IpBan $b) => [
                'id' => $b->id, 'ip' => $b->ip, 'reason' => $b->reason, 'automatic' => $b->automatic, 'active' => $b->isActive(),
                'until' => $b->until ? Dates::display($b->until, 'j M Y, g:ia') : null,
                'when' => Dates::display($b->created_at, 'j M Y, g:ia'),
            ]) : null,
            'broadcasts' => $tab === 'broadcasts' ? Broadcast::latest('id')->limit(50)->get()->map(fn (Broadcast $b) => [
                'id' => $b->id, 'title' => $b->title, 'body' => $b->body, 'audience' => Broadcast::AUDIENCES[$b->audience] ?? $b->audience,
                'recipients' => $b->recipients, 'when' => Dates::display($b->created_at, 'j M Y, g:ia'),
            ]) : null,
            'audiences' => collect(Broadcast::AUDIENCES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'settings' => ChatSettings::all(),
        ]);
    }

    /** The conversation around a report: the reported message and what came before and after. */
    public function context(Report $report): JsonResponse
    {
        $c = $report->conversation ?? $report->message?->conversation;

        if (! $c) {
            return response()->json(['messages' => []]);
        }

        $anchor = $report->message_id ?: (int) $c->last_message_id;
        $before = Message::where('conversation_id', $c->id)->where('id', '<=', $anchor)->orderByDesc('id')->limit(25)->get()->reverse();
        $after = Message::where('conversation_id', $c->id)->where('id', '>', $anchor)->orderBy('id')->limit(10)->get();
        $names = User::whereIn('id', [$c->user_low_id, $c->user_high_id])->pluck('name', 'id');

        return response()->json(['messages' => $before->concat($after)->map(fn (Message $m) => [
            'id' => $m->id,
            'sender' => $names[$m->sender_id] ?? 'Unknown',
            'reported_user' => (int) $m->sender_id === (int) $report->reported_user_id,
            'body' => $m->body,
            'image' => $m->image_path && ! $m->deleted_at ? route('chat.media', ['message' => $m->id, 'variant' => 'thumb'], false) : null,
            'deleted' => (bool) $m->deleted_at,
            'flagged' => $m->id === (int) $report->message_id,
            'ip' => $m->ip,
            'at' => Dates::display($m->created_at, 'j M, g:ia'),
        ])->values()]);
    }

    public function resolve(Request $request, Report $report, Messenger $messenger): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['dismiss', 'remove_message', 'suspend', 'ban_ip'])],
            'days' => ['nullable', 'integer', 'min:0', 'max:3650'],      // 0 = forever
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $label = 'Dismissed';

        switch ($data['action']) {
            case 'remove_message':
                if ($report->message) {
                    $messenger->remove($report->message, true);
                }
                $label = 'Message removed';
                break;

            case 'suspend':
                $this->suspendUser($report->reported_user_id, (int) ($data['days'] ?? 7), $data['note'] ?? 'Reported: '.(Report::REASONS[$report->reason] ?? $report->reason));
                $label = 'Suspended '.((int) ($data['days'] ?? 7) === 0 ? 'permanently' : ($data['days'] ?? 7).' days');
                break;

            case 'ban_ip':
                $ip = $report->message?->ip ?? Message::where('sender_id', $report->reported_user_id)->whereNotNull('ip')->latest('id')->value('ip');
                if ($ip) {
                    $days = (int) ($data['days'] ?? 7);
                    IpGuard::ban($ip, 'Report #'.$report->id, $days === 0 ? null : now()->addDays($days), by: $request->user()->id);
                }
                $label = $ip ? "IP {$ip} banned" : 'No IP on record';
                break;
        }

        // Every open report about the same person is settled together.
        Report::where('reported_user_id', $report->reported_user_id)->where('status', 'open')->update([
            'status' => $data['action'] === 'dismiss' ? 'dismissed' : 'actioned',
            'action' => $label,
            'handled_by' => $request->user()->id,
            'handled_at' => now(),
        ]);

        return back()->with('flash_success', $label.'.');
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:0', 'max:3650'], 'reason' => ['required', 'string', 'max:255']]);
        $this->suspendUser($user->id, (int) $data['days'], $data['reason']);

        return back()->with('flash_success', "{$user->name} can no longer send messages.");
    }

    public function unsuspend(User $user): RedirectResponse
    {
        UserState::for($user->id)->forceFill(['suspended_until' => null, 'suspended_forever' => false, 'suspended_reason' => null])->save();

        return back()->with('flash_success', "{$user->name} can message again.");
    }

    public function banIp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'hours' => ['required', 'integer', 'min:0', 'max:87600'],   // 0 = permanent
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['ip'] === $request->ip()) {
            return back()->with('flash_error', 'That is your own IP address.');
        }

        IpGuard::ban($data['ip'], $data['reason'] ?? null, (int) $data['hours'] === 0 ? null : now()->addHours((int) $data['hours']), by: $request->user()->id);

        return back()->with('flash_success', "{$data['ip']} is banned from chat.");
    }

    public function unbanIp(IpBan $ban): RedirectResponse
    {
        IpGuard::unban($ban->ip);

        return back()->with('flash_success', "{$ban->ip} is unbanned.");
    }

    /** An announcement to everyone in an audience, as a pinned thread in their inbox. */
    public function broadcast(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],
            'audience' => ['required', Rule::in(array_keys(Broadcast::AUDIENCES))],
        ]);

        $recipients = match ($data['audience']) {
            'organizers' => User::whereHas('roles', fn ($q) => $q->where('name', 'organizer'))->count(),
            'buyers' => Order::whereNotNull('paid_at')->distinct()->count('user_id'),
            default => User::count(),
        };

        $b = Broadcast::create($data + ['recipients' => $recipients, 'sent_by' => $request->user()->id]);
        // One cache write reaches everyone: every open poll sees the stamp change.
        Realtime::setBroadcastVersion((int) floor(microtime(true) * 1000));

        return back()->with('flash_success', 'Announcement sent to '.number_format($recipients).' people.');
    }

    public function deleteBroadcast(Broadcast $broadcast): RedirectResponse
    {
        $broadcast->delete();
        Realtime::setBroadcastVersion((int) floor(microtime(true) * 1000));

        return back()->with('flash_success', 'Announcement deleted.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $rules = ['images_enabled' => ['boolean'], 'enabled' => ['boolean']];
        foreach (ChatSettings::SPEC as $key => [, $min, $max]) {
            $rules[$key] = ['required', 'integer', "min:{$min}", "max:{$max}"];
        }

        ChatSettings::save($request->validate($rules));

        return back()->with('flash_success', 'Chat settings saved.');
    }

    private function suspendUser(int $userId, int $days, string $reason): void
    {
        UserState::for($userId)->forceFill([
            'suspended_forever' => $days === 0,
            'suspended_until' => $days === 0 ? null : now()->addDays($days),
            'suspended_reason' => mb_substr($reason, 0, 255),
        ])->save();
    }
}
