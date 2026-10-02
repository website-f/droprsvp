import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowLeft, Ban, BadgeCheck, Flag, Loader2, Megaphone, MessageSquarePlus, MessagesSquare, MoreVertical, Search, ShieldAlert, Trash2, UserRound } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Composer } from '@/components/chat/composer';
import { NewChatDialog, ReportDialog } from '@/components/chat/dialogs';
import { AnnouncementsAvatar, Bubble, ConversationRow, dayLabel, DaySeparator, PersonAvatar, presence, shortTime, TypingDots } from '@/components/chat/parts';
import type { ChatMessage, InboxItem, Person, Thread } from '@/components/chat/parts';
import { useChatPoll } from '@/components/chat/use-chat-poll';
import type { PollResponse } from '@/components/chat/use-chat-poll';
import { useConfirm } from '@/components/confirm-dialog';
import { ImageLightbox } from '@/components/image-lightbox';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { chatStore } from '@/lib/chat-store';

interface Counts { unread: number; requests: number; announcements: number }
interface Props {
    me: { id: number; name: string; admin: boolean };
    inbox: InboxItem[];
    counts: Counts;
    selected: (Thread & { messages: ChatMessage[]; typing: boolean }) | null;
    draft: (Person & { blocked: boolean; request: boolean }) | null;
    realtime: { token: string; v: number; bv: number };
    config: { max_length: number; images: boolean; max_image_mb: number; request_limit: number; poll: Record<string, number> };
    suspended: string | null;
    reasons: { value: string; label: string }[];
}

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

async function call(url: string, method: 'POST' | 'DELETE' = 'POST', body?: FormData) {
    const res = await fetch(url, { method, body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });
    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        throw Object.assign(new Error(data.message ?? 'Request failed'), { data, status: res.status });
    }

    return data;
}

export default function ChatPage({ inbox: initialInbox, counts: initialCounts, selected, draft, realtime, config, suspended, reasons }: Props) {
    const confirm = useConfirm();
    const [inbox, setInbox] = useState(initialInbox);
    const [counts, setCounts] = useState(initialCounts);
    const [tab, setTab] = useState<'chats' | 'requests'>(() => (selected?.request ? 'requests' : 'chats'));
    const [filter, setFilter] = useState('');
    const [thread, setThread] = useState<Thread | null>(selected);
    const [messages, setMessages] = useState<ChatMessage[]>(selected?.messages ?? []);
    const [typing, setTyping] = useState(selected?.typing ?? false);
    const [hasMore, setHasMore] = useState((selected?.messages.length ?? 0) >= 40);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [announcements, setAnnouncements] = useState<{ id: number; title: string; body: string; at: string }[] | null>(null);
    const [newChat, setNewChat] = useState(false);
    const [reportTarget, setReportTarget] = useState<{ userId: number; name: string; conversationId?: number; messageId?: number } | null>(null);
    const [lightbox, setLightbox] = useState<string | null>(null);
    const [newBelow, setNewBelow] = useState(0);

    // A different conversation arrived as a prop (navigation): take it over.
    const [syncedId, setSyncedId] = useState<number | null>(selected?.id ?? null);

    if ((selected?.id ?? null) !== syncedId) {
        setSyncedId(selected?.id ?? null);
        setThread(selected);
        setMessages(selected?.messages ?? []);
        setTyping(selected?.typing ?? false);
        setHasMore((selected?.messages.length ?? 0) >= 40);
        setNewBelow(0);

        if (selected) {
            setAnnouncements(null);
            setInbox((list) => list.map((i) => (i.id === selected.id ? { ...i, unread: 0 } : i)));
        }
    }

    const view: 'thread' | 'draft' | 'announcements' | 'none' = announcements ? 'announcements' : thread ? 'thread' : draft ? 'draft' : 'none';

    // ---- scrolling --------------------------------------------------------------
    const scroller = useRef<HTMLDivElement>(null);
    const stick = useRef(true);
    const prepend = useRef<number | null>(null);

    useLayoutEffect(() => {
        const el = scroller.current;

        if (!el) {
            return;
        }

        if (prepend.current !== null) {
            el.scrollTop += el.scrollHeight - prepend.current;
            prepend.current = null;
        } else if (stick.current) {
            el.scrollTop = el.scrollHeight;
        }
    }, [messages, typing, view]);

    useLayoutEffect(() => {
        stick.current = true;
    }, [syncedId]);

    const toBottom = () => {
        scroller.current?.scrollTo({ top: scroller.current.scrollHeight, behavior: 'smooth' });
        setNewBelow(0);
    };

    // ---- live updates -------------------------------------------------------------
    const lastRealId = useMemo(() => messages.reduce((max, m) => (m.id > 0 && m.id > max ? m.id : max), 0), [messages]);

    const onData = useCallback((data: PollResponse) => {
        if (data.counts) {
            setCounts(data.counts);
            chatStore.set({ unread: data.counts.unread + data.counts.requests });
        }

        if (data.inbox) {
            setInbox(data.inbox as InboxItem[]);
        }

        const open = data.open as (Thread & { messages: ChatMessage[]; removed?: number[] }) | undefined;

        setTyping(data.t);

        if (open && open.id === syncedId) {
            const { messages: incoming, removed = [], ...header } = open;
            setThread(header);
            setMessages((list) => {
                const have = new Set(list.map((m) => m.id));
                const fresh = incoming.filter((m) => !have.has(m.id));

                if (fresh.length && !stick.current) {
                    setNewBelow((n) => n + fresh.filter((m) => !m.mine).length);
                }

                const removedSet = new Set(removed);

                return [...list.map((m) => (removedSet.has(m.id) && !m.deleted ? { ...m, deleted: true, body: null, image: null } : m)), ...fresh];
            });
        }
    }, [syncedId]);

    const { pollNow } = useChatPoll({
        token: realtime.token,
        v: realtime.v,
        bv: realtime.bv,
        hiddenSeconds: config.poll.hidden,
        fallbackSeconds: config.poll.inbox,
        params: () => (thread ? { mode: 'open', c: thread.id, after: lastRealId } : { mode: 'inbox' }),
        onData,
    });

    useEffect(() => {
        chatStore.set({ chatPageOpen: true, unread: initialCounts.unread + initialCounts.requests });

        return () => chatStore.set({ chatPageOpen: false });
    }, [initialCounts.unread, initialCounts.requests]);

    // ---- navigation ------------------------------------------------------------------
    const open = (id: number) => router.visit(`/messages/${id}`, { only: ['selected', 'draft'], preserveState: true, preserveScroll: true });
    const back = () => {
        setAnnouncements(null);
        router.visit('/messages', { only: ['selected', 'draft'], preserveState: true, preserveScroll: true });
    };

    const openAnnouncements = async () => {
        try {
            const res = await fetch('/chat/announcements', { headers: { Accept: 'application/json' } });
            setAnnouncements((await res.json()).items ?? []);
            setCounts((c) => ({ ...c, announcements: 0 }));
        } catch {
            toast.error('Could not load announcements.');
        }
    };

    // ---- sending ----------------------------------------------------------------------
    const tempSeq = useRef(0);
    const send = async (body: string, image: File | null, replacing?: number) => {
        tempSeq.current += 1;
        const temp: ChatMessage = {
            id: -tempSeq.current, mine: true, body: body || null, image: null, deleted: false, at: new Date().toISOString(),
            state: 'sending', localImage: image ? URL.createObjectURL(image) : undefined, tempFile: image,
        };

        stick.current = true;
        setMessages((list) => [...list.filter((m) => m.id !== replacing), temp]);

        const fd = new FormData();

        if (thread) {
            fd.append('conversation_id', String(thread.id));
        } else if (draft) {
            fd.append('recipient_id', String(draft.id));
        }

        if (body) {
            fd.append('body', body);
        }

        if (image) {
            fd.append('image', image);
        }

        try {
            const data = await call('/chat/messages', 'POST', fd);
            setMessages((list) => (list.some((m) => m.id === data.message.id)
                ? list.filter((m) => m.id !== temp.id)
                : list.map((m) => (m.id === temp.id ? data.message : m))));
            setThread(data.conversation);

            if (!thread) {
                // First message to someone new: the conversation now exists.
                setSyncedId(data.conversation.id);
                router.visit(`/messages/${data.conversation.id}`, { only: ['selected', 'draft'], preserveState: true, preserveScroll: true });
            }

            pollNow();
        } catch (e) {
            const message = (e as Error).message;
            setMessages((list) => list.map((m) => (m.id === temp.id ? { ...m, state: 'failed', error: message } : m)));
            toast.error(message);
        }
    };

    const lastTyping = useRef(0);
    const onTyping = () => {
        if (thread && thread.status === 'active' && Date.now() - lastTyping.current > 3000) {
            lastTyping.current = Date.now();
            void call(`/chat/conversations/${thread.id}/typing`).catch(() => {});
        }
    };

    const loadOlder = async () => {
        if (!thread || loadingOlder || !hasMore) {
            return;
        }

        const first = messages.find((m) => m.id > 0);

        if (!first) {
            return;
        }

        setLoadingOlder(true);

        try {
            const res = await fetch(`/chat/conversations/${thread.id}/older?before=${first.id}`, { headers: { Accept: 'application/json' } });
            const older: ChatMessage[] = (await res.json()).messages ?? [];
            prepend.current = scroller.current?.scrollHeight ?? null;
            setMessages((list) => [...older, ...list]);
            setHasMore(older.length >= 40);
        } finally {
            setLoadingOlder(false);
        }
    };

    // ---- conversation actions ----------------------------------------------------------
    const act = async (path: string, method: 'POST' | 'DELETE' = 'POST') => {
        try {
            return await call(path, method);
        } catch (e) {
            toast.error((e as Error).message);

            return null;
        }
    };

    const accept = async () => {
        if (thread) {
            const header = await act(`/chat/conversations/${thread.id}/accept`);

            if (header) {
                setThread(header);
                setTab('chats');
                pollNow();
            }
        }
    };

    const decline = async () => {
        if (thread && await confirm({ title: 'Delete this request?', description: `${thread.other.name} won’t be told, and can’t send more messages unless you write to them.`, confirmText: 'Delete', destructive: true })) {
            await act(`/chat/conversations/${thread.id}/decline`);
            back();
            pollNow();
        }
    };

    const toggleBlock = async (other: Person, blocked: boolean) => {
        if (!blocked && !(await confirm({ title: `Block ${other.name}?`, description: 'Neither of you will be able to message the other. They won’t be told.', confirmText: 'Block', destructive: true }))) {
            return;
        }

        await act(`/chat/users/${other.id}/block`, blocked ? 'DELETE' : 'POST');
        toast.success(blocked ? 'Unblocked.' : 'Blocked.');
        pollNow();

        if (thread) {
            setThread({ ...thread, blocked_by_me: !blocked });
        }
    };

    const hide = async () => {
        if (thread && await confirm({ title: 'Delete this chat?', description: 'It leaves your inbox. If they write again it comes back. They keep their copy.', confirmText: 'Delete', destructive: true })) {
            await act(`/chat/conversations/${thread.id}/hide`);
            back();
            pollNow();
        }
    };

    const unsend = async (m: ChatMessage) => {
        // The server enforces the 24-hour window and says so if it has passed.
        if (await confirm({ title: 'Remove for everyone?', description: 'The message disappears for both of you.', confirmText: 'Remove', destructive: true })) {
            const updated = await act(`/chat/messages/${m.id}`, 'DELETE');

            if (updated) {
                setMessages((list) => list.map((x) => (x.id === m.id ? updated : x)));
            }
        }
    };

    // ---- derived ----------------------------------------------------------------------
    const visibleInbox = inbox.filter((i) => (tab === 'requests' ? i.request : !i.request))
        .filter((i) => !filter.trim() || i.other.name.toLowerCase().includes(filter.trim().toLowerCase()));

    const header: Person | null = thread?.other ?? draft ?? null;
    const composerBlocked = suspended
        ? suspended
        : thread?.blocked_by_me ? 'You blocked this person. Unblock them to reply.'
            : thread?.blocked_me || draft?.blocked ? 'You can’t message this person.'
                : thread?.request ? null
                    : thread?.pending && thread.pending_left <= 0 ? `Waiting for ${thread.other.name} to accept your request.`
                        : thread?.status === 'declined' && thread.pending ? 'You can’t send more until they reply.'
                            : null;

    const showList = view === 'none';

    return (
        <>
            <Head title={counts.unread ? `(${counts.unread}) Messages` : 'Messages'} />
            <div className="flex h-[calc(100svh-4rem)] min-h-0 overflow-hidden bg-card md:h-[calc(100svh-5rem)] md:rounded-b-xl">
                {/* ---- inbox ---- */}
                <aside className={`${showList ? 'flex' : 'hidden'} w-full min-w-0 flex-col border-r border-border md:flex md:w-80 lg:w-96`}>
                    <div className="flex items-center justify-between gap-2 px-4 pb-2 pt-4">
                        <h1 className="text-xl font-bold tracking-tight">Messages</h1>
                        <Button size="icon" variant="ghost" onClick={() => setNewChat(true)} aria-label="New message" disabled={!!suspended}><MessageSquarePlus className="size-5" /></Button>
                    </div>
                    <div className="px-4 pb-2">
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Search chats" className="h-10 w-full rounded-full border border-transparent bg-muted/60 pl-9 pr-3 text-[16px] outline-none focus:border-ring focus:bg-card sm:text-sm" />
                        </div>
                    </div>
                    <div className="flex gap-1 px-4 pb-2">
                        {(['chats', 'requests'] as const).map((t) => (
                            <button key={t} type="button" onClick={() => setTab(t)} className={`rounded-full px-3 py-1.5 text-xs font-semibold transition-colors ${tab === t ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted'}`}>
                                {t === 'chats' ? 'Chats' : 'Requests'}
                                {t === 'requests' && counts.requests > 0 && <span className="ml-1.5 rounded-full bg-primary px-1.5 text-[10px] text-primary-foreground">{counts.requests}</span>}
                            </button>
                        ))}
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                        {tab === 'chats' && (
                            <button type="button" onClick={openAnnouncements} className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors ${view === 'announcements' ? 'bg-primary/10' : 'hover:bg-muted/60'}`}>
                                <AnnouncementsAvatar size={46} />
                                <span className="min-w-0 flex-1">
                                    <span className="flex items-center gap-1.5 text-sm font-medium">DropRSVP <BadgeCheck className="size-3.5 text-primary" /></span>
                                    <span className={`block truncate text-xs ${counts.announcements ? 'font-semibold text-foreground' : 'text-muted-foreground'}`}>{counts.announcements ? `${counts.announcements} new announcement${counts.announcements === 1 ? '' : 's'}` : 'Announcements'}</span>
                                </span>
                                {counts.announcements > 0 && <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-bold text-primary-foreground">{counts.announcements}</span>}
                            </button>
                        )}

                        {visibleInbox.map((i) => <ConversationRow key={i.id} item={i} active={thread?.id === i.id} onSelect={() => open(i.id)} />)}

                        {visibleInbox.length === 0 && (
                            <div className="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-muted-foreground">
                                <MessagesSquare className="size-8" />
                                {tab === 'requests' ? 'No message requests. When someone you don’t know writes, it lands here first.'
                                    : filter ? 'No chats match.' : 'No messages yet. Start one with an organizer or someone you follow.'}
                                {tab === 'chats' && !filter && <Button size="sm" variant="outline" className="mt-1" onClick={() => setNewChat(true)} disabled={!!suspended}>New message</Button>}
                            </div>
                        )}
                    </div>
                </aside>

                {/* ---- conversation ---- */}
                <section className={`${showList ? 'hidden' : 'flex'} min-w-0 flex-1 flex-col md:flex`}>
                    {view === 'none' && (
                        <div className="hidden flex-1 flex-col items-center justify-center gap-3 p-8 text-center md:flex">
                            <span className="flex size-16 items-center justify-center rounded-full bg-primary/10 text-primary"><MessagesSquare className="size-8" /></span>
                            <div className="text-lg font-semibold">Your messages</div>
                            <p className="max-w-sm text-sm text-muted-foreground">Chat with organizers about their events, or with people you know. New conversations from strangers arrive as requests.</p>
                            <Button onClick={() => setNewChat(true)} disabled={!!suspended}><MessageSquarePlus className="size-4" /> New message</Button>
                        </div>
                    )}

                    {view === 'announcements' && (
                        <>
                            <header className="flex items-center gap-3 border-b border-border px-3 py-2.5 sm:px-4">
                                <Button size="icon" variant="ghost" className="md:hidden" onClick={back} aria-label="Back"><ArrowLeft className="size-5" /></Button>
                                <AnnouncementsAvatar size={40} />
                                <div><div className="flex items-center gap-1 font-semibold">DropRSVP <BadgeCheck className="size-4 text-primary" /></div><div className="text-xs text-muted-foreground">Announcements</div></div>
                            </header>
                            <div className="min-h-0 flex-1 overflow-y-auto bg-muted/20 p-4">
                                {announcements?.length === 0 && <p className="py-12 text-center text-sm text-muted-foreground">No announcements yet.</p>}
                                <div className="mx-auto grid max-w-2xl gap-3">
                                    {announcements?.map((a) => (
                                        <article key={a.id} className="rounded-2xl border border-border bg-card p-4 shadow-sm">
                                            <div className="mb-1 flex items-center gap-2 text-xs text-muted-foreground"><Megaphone className="size-3.5" /> {shortTime(a.at)}</div>
                                            <h3 className="font-semibold">{a.title}</h3>
                                            <p className="mt-1 whitespace-pre-wrap text-sm text-muted-foreground">{a.body}</p>
                                        </article>
                                    ))}
                                </div>
                            </div>
                        </>
                    )}

                    {(view === 'thread' || view === 'draft') && header && (
                        <>
                            <header className="flex items-center gap-2 border-b border-border px-2 py-2 sm:gap-3 sm:px-4">
                                <Button size="icon" variant="ghost" className="shrink-0 md:hidden" onClick={back} aria-label="Back"><ArrowLeft className="size-5" /></Button>
                                <PersonAvatar person={header} size={40} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-1 truncate font-semibold">{header.name}{header.organizer && <BadgeCheck className="size-4 shrink-0 text-primary" />}</div>
                                    <div className={`truncate text-xs ${typing ? 'text-primary' : 'text-muted-foreground'}`}>{presence(header, view === 'thread' && typing)}</div>
                                </div>
                                {view === 'thread' && thread && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild><Button size="icon" variant="ghost" aria-label="Conversation options"><MoreVertical className="size-5" /></Button></DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            {header.profile_url && <DropdownMenuItem asChild><Link href={header.profile_url}><UserRound className="size-4" /> View profile</Link></DropdownMenuItem>}
                                            <DropdownMenuItem onClick={() => toggleBlock(header, thread.blocked_by_me)}><Ban className="size-4" /> {thread.blocked_by_me ? 'Unblock' : 'Block'}</DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => setReportTarget({ userId: header.id, name: header.name, conversationId: thread.id })}><Flag className="size-4" /> Report</DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem className="text-destructive" onClick={hide}><Trash2 className="size-4" /> Delete chat</DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </header>

                            <div className="relative min-h-0 flex-1">
                                <div
                                    ref={scroller}
                                    className="h-full overflow-y-auto overscroll-contain bg-muted/20 px-3 py-4 sm:px-6"
                                    onScroll={(e) => {
                                        const el = e.currentTarget;
                                        stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120;

                                        if (stick.current) {
                                            setNewBelow(0);
                                        }

                                        if (el.scrollTop < 80) {
                                            void loadOlder();
                                        }
                                    }}
                                >
                                    <div className="mx-auto flex max-w-3xl flex-col gap-1">
                                        {loadingOlder && <div className="flex justify-center py-2"><Loader2 className="size-4 animate-spin text-muted-foreground" /></div>}

                                        {view === 'draft' && draft && (
                                            <div className="mx-auto my-8 flex max-w-sm flex-col items-center gap-2 text-center">
                                                <PersonAvatar person={draft} size={72} showOnline={false} />
                                                <div className="font-semibold">{draft.name}</div>
                                                <p className="text-sm text-muted-foreground">{draft.request
                                                    ? `Your first message arrives as a request. You can send up to ${config.request_limit} messages until ${draft.name} accepts.`
                                                    : 'Say hello — your message goes straight to their inbox.'}</p>
                                            </div>
                                        )}

                                        {messages.map((m, i) => {
                                            const prev = messages[i - 1];
                                            const next = messages[i + 1];
                                            const newDay = !prev || new Date(prev.at).toDateString() !== new Date(m.at).toDateString();
                                            const tail = !next || next.mine !== m.mine || new Date(next.at).getTime() - new Date(m.at).getTime() > 5 * 60_000;
                                            const gap = prev && prev.mine !== m.mine;

                                            return (
                                                <div key={m.id} className={gap ? 'mt-2' : ''}>
                                                    {newDay && <DaySeparator label={dayLabel(m.at)} />}
                                                    <Bubble
                                                        m={m}
                                                        tail={tail}
                                                        seen={m.mine && m.id > 0 && !!thread && thread.their_read_id >= m.id}
                                                        onImage={(src) => setLightbox(src)}
                                                        onRetry={() => send(m.body ?? '', m.tempFile ?? null, m.id)}
                                                        onUnsend={m.mine && m.id > 0 ? () => unsend(m) : undefined}
                                                    />
                                                </div>
                                            );
                                        })}

                                        {typing && view === 'thread' && <div className="mt-2"><TypingDots /></div>}
                                    </div>
                                </div>

                                {newBelow > 0 && (
                                    <button type="button" onClick={toBottom} className="absolute bottom-3 left-1/2 inline-flex -translate-x-1/2 items-center gap-1 rounded-full bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground shadow-lg">
                                        <ArrowDown className="size-3.5" /> {newBelow} new message{newBelow === 1 ? '' : 's'}
                                    </button>
                                )}
                            </div>

                            {/* Request banners */}
                            {thread?.request && (
                                <div className="border-t border-border bg-amber-500/5 p-3 sm:p-4">
                                    <p className="mb-3 text-sm"><span className="font-semibold">{thread.other.name}</span> wants to message you. They won’t know you’ve seen this until you reply or accept.</p>
                                    <div className="grid grid-cols-3 gap-2">
                                        <Button onClick={accept}>Accept</Button>
                                        <Button variant="outline" onClick={decline}>Delete</Button>
                                        <Button variant="outline" className="text-destructive" onClick={() => setReportTarget({ userId: thread.other.id, name: thread.other.name, conversationId: thread.id })}><ShieldAlert className="size-4" /> Report</Button>
                                    </div>
                                </div>
                            )}
                            {thread?.pending && thread.status === 'request' && thread.pending_left > 0 && (
                                <div className="border-t border-border bg-muted/40 px-4 py-2 text-center text-xs text-muted-foreground">
                                    Message request sent — you can send {thread.pending_left} more until {thread.other.name} accepts.
                                </div>
                            )}

                            <Composer
                                disabled={!!composerBlocked}
                                disabledReason={composerBlocked}
                                imagesAllowed={config.images && !thread?.pending && !draft?.request}
                                maxLength={config.max_length}
                                maxImageMb={config.max_image_mb}
                                onSend={(body, image) => void send(body, image)}
                                onTyping={onTyping}
                            />
                        </>
                    )}
                </section>
            </div>

            <NewChatDialog open={newChat} onOpenChange={setNewChat} />
            <ReportDialog
                target={reportTarget}
                reasons={reasons}
                onOpenChange={(o) => !o && setReportTarget(null)}
                onDone={(blocked) => {
                    if (blocked && thread) {
                        setThread({ ...thread, blocked_by_me: true });
                    }

                    pollNow();
                }}
            />
            <ImageLightbox images={lightbox ? [{ src: lightbox }] : []} index={lightbox ? 0 : null} onClose={() => setLightbox(null)} onIndexChange={() => {}} />
        </>
    );
}

ChatPage.layout = { breadcrumbs: [{ title: 'Messages', href: '/messages' }] };
