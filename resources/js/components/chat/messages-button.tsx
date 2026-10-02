import { Link, usePage } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { useEffect } from 'react';
import { useChatPoll } from '@/components/chat/use-chat-poll';
import { chatStore, useChatStore } from '@/lib/chat-store';

type SharedChat = { unread: number; token: string; v: number; bv: number } | null | undefined;

/**
 * Header shortcut to Messages with the unread badge. It also runs the one
 * slow "badge" poller for every page except Messages itself (which runs its
 * own, faster one) — the server answers that mode from cache in microseconds
 * and tells it to come back in ~45s.
 */
export function MessagesButton() {
    const chat = usePage().props.chat as SharedChat;
    const { unread, chatPageOpen } = useChatStore();

    // Every full page load brings a fresh count; adopt it.
    useEffect(() => {
        if (chat && !chatStore.get().chatPageOpen) {
            chatStore.set({ unread: chat.unread });
        }
    }, [chat]);

    useChatPoll({
        token: chat?.token ?? '',
        v: chat?.v ?? 0,
        bv: chat?.bv ?? 0,
        params: () => ({ mode: 'badge' }),
        onData: (d) => d.counts && chatStore.set({ unread: d.counts.unread + d.counts.requests }),
        fallbackSeconds: 45,
        hiddenSeconds: 120,
        // This page load already brought the count; the first check can wait.
        initialDelay: 30,
        enabled: !!chat && !chatPageOpen,
    });

    if (!chat) {
        return null;
    }

    return (
        <Link href="/messages" aria-label={unread ? `Messages, ${unread} unread` : 'Messages'} className="relative flex size-10 items-center justify-center rounded-lg border border-border transition-colors hover:bg-accent">
            <MessageCircle className="size-5" />
            {unread > 0 && (
                <span className="absolute -right-1 -top-1 flex min-w-[1.1rem] items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold leading-4 text-primary-foreground">{unread > 9 ? '9+' : unread}</span>
            )}
        </Link>
    );
}
