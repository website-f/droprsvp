<?php

namespace App\Console\Commands;

use App\Mail\ChatUnreadMail;
use App\Models\Chat\Message;
use App\Models\Chat\Participant;
use App\Models\Chat\UserState;
use App\Models\User;
use App\Services\Chat\Messenger;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\Realtime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Email people about messages they have not read.
 *
 * Only when it is useful, and never as a flood:
 *  - the message has waited notify_after_minutes;
 *  - they are not online (no poll in that time — they would have seen it);
 *  - there is something newer than what we last emailed them about;
 *  - at most one email per notify_cooldown_hours;
 *  - they have not turned it off in Settings → Notifications.
 */
class NotifyUnreadChats extends Command
{
    protected $signature = 'chat:notify';

    protected $description = 'Email users about unread chat messages';

    public function handle(): int
    {
        $s = ChatSettings::all();
        $waited = now()->subMinutes($s['notify_after_minutes']);
        $sent = 0;

        $userIds = Participant::query()
            ->where('unread_count', '>', 0)
            ->whereNull('hidden_at')
            ->join('chat_conversations', 'chat_conversations.id', '=', 'chat_participants.conversation_id')
            ->where('chat_conversations.status', 'active')
            ->where('chat_conversations.last_message_at', '<=', $waited)
            ->distinct()
            ->limit(500)
            ->pluck('chat_participants.user_id');

        foreach ($userIds as $userId) {
            $user = User::find($userId);

            if (! $user || ! $user->email || $user->isDisabled() || ! $user->wantsNotification('chat_messages')) {
                continue;
            }

            // Online since the message arrived: they have it in front of them.
            $seen = Realtime::lastSeen($userId);
            if ($seen && $seen >= $waited->getTimestamp()) {
                continue;
            }

            $state = UserState::for($userId);
            if ($state->last_notified_at && $state->last_notified_at->gt(now()->subHours($s['notify_cooldown_hours']))) {
                continue;
            }

            $threads = Participant::where('chat_participants.user_id', $userId)
                ->where('unread_count', '>', 0)
                ->whereNull('hidden_at')
                ->join('chat_conversations', 'chat_conversations.id', '=', 'chat_participants.conversation_id')
                ->where('chat_conversations.status', 'active')
                ->orderByDesc('chat_conversations.last_message_at')
                ->get(['chat_participants.*', 'chat_conversations.last_message_id']);

            $newest = (int) $threads->max('last_message_id');
            if ($newest <= (int) $state->notified_upto_message_id) {
                continue; // already told them about everything here
            }

            $people = Messenger::people($threads->pluck('other_user_id')->all());
            $last = Message::whereIn('id', $threads->pluck('last_message_id'))->get()->keyBy('id');

            $items = $threads->take(5)->map(fn ($t) => [
                'name' => $people[$t->other_user_id]['name'] ?? 'Someone',
                'preview' => ($m = $last->get($t->last_message_id)) && $m->body ? Str::limit(preg_replace('/\s+/', ' ', $m->body), 90) : 'Sent a photo',
                'count' => (int) $t->unread_count,
            ])->values()->all();

            try {
                Mail::to($user->email)->send(new ChatUnreadMail($user->name, (int) $threads->sum('unread_count'), $items));
                $sent++;
            } catch (\Throwable $e) {
                report($e);

                continue;
            }

            $state->forceFill(['last_notified_at' => now(), 'notified_upto_message_id' => $newest])->save();
        }

        if ($sent > 0) {
            $this->info("Emailed {$sent} user(s) about unread messages.");
        }

        return self::SUCCESS;
    }
}
