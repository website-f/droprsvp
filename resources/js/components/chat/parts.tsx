import { AlertCircle, BadgeCheck, Ban, Check, CheckCheck, Clock, ImageIcon, Megaphone } from 'lucide-react';

// ---- types ------------------------------------------------------------------

export interface Person {
    id: number; name: string; avatar: string | null; organizer: boolean; profile_url: string | null; online: boolean; last_seen: string | null;
}

export interface InboxItem {
    id: number; other: Person; status: 'active' | 'request' | 'declined'; request: boolean; pending: boolean; blocked: boolean; unread: number;
    last: { text: string; image: boolean; mine: boolean; at: string } | null;
}

export interface ChatMessage {
    id: number; mine: boolean; body: string | null; image: { thumb: string; full: string; w: number; h: number } | null;
    deleted: boolean; removed_by_admin?: boolean; at: string;
    /** Client-side only: an optimistic message not yet confirmed. */
    state?: 'sending' | 'failed';
    error?: string;
    localImage?: string;
    tempFile?: File | null;
}

export interface Thread {
    id: number; other: Person; status: string; request: boolean; pending: boolean; pending_left: number;
    blocked_by_me: boolean; blocked_me: boolean; their_read_id: number; my_read_id: number;
}

// ---- time ---------------------------------------------------------------------

const sameDay = (a: Date, b: Date) => a.toDateString() === b.toDateString();

/** "14:05", "Yesterday", "Mon", "3 Oct" — as an inbox shows it. */
export function shortTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();

    if (sameDay(d, now)) {
        return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (sameDay(d, yesterday)) {
        return 'Yesterday';
    }

    if (now.getTime() - d.getTime() < 6 * 86_400_000) {
        return d.toLocaleDateString([], { weekday: 'short' });
    }

    return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
}

export function dayLabel(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (sameDay(d, now)) {
        return 'Today';
    }

    if (sameDay(d, yesterday)) {
        return 'Yesterday';
    }

    return d.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' });
}

export function presence(p: Person, typing: boolean): string {
    if (typing) {
        return 'typing…';
    }

    if (p.online) {
        return 'Online';
    }

    if (!p.last_seen) {
        return p.organizer ? 'Organizer' : '';
    }

    const mins = Math.round((Date.now() - new Date(p.last_seen).getTime()) / 60000);

    if (mins < 60) {
        return `Active ${Math.max(1, mins)}m ago`;
    }

    if (mins < 1440) {
        return `Active ${Math.round(mins / 60)}h ago`;
    }

    return `Active ${shortTime(p.last_seen)}`;
}

// ---- avatar ---------------------------------------------------------------------

const COLORS = ['#6c63ff', '#f97316', '#22c55e', '#3b82f6', '#ec4899', '#14b8a6', '#a855f7', '#eab308'];

export function PersonAvatar({ person, size = 40, showOnline = true }: { person: Pick<Person, 'id' | 'name' | 'avatar' | 'online'>; size?: number; showOnline?: boolean }) {
    const initials = person.name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';

    return (
        <span className="relative inline-flex shrink-0" style={{ width: size, height: size }}>
            {person.avatar ? (
                <img src={person.avatar} alt="" className="size-full rounded-full object-cover" loading="lazy" />
            ) : (
                <span className="flex size-full items-center justify-center rounded-full text-white" style={{ backgroundColor: COLORS[person.id % COLORS.length], fontSize: size * 0.38, fontWeight: 600 }}>
                    {initials}
                </span>
            )}
            {showOnline && person.online && (
                <span className="absolute bottom-0 right-0 rounded-full border-2 border-card bg-emerald-500" style={{ width: Math.max(10, size * 0.28), height: Math.max(10, size * 0.28) }} aria-label="Online" />
            )}
        </span>
    );
}

export function AnnouncementsAvatar({ size = 40 }: { size?: number }) {
    return (
        <span className="flex shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground" style={{ width: size, height: size }}>
            <Megaphone style={{ width: size * 0.45, height: size * 0.45 }} />
        </span>
    );
}

// ---- inbox row -------------------------------------------------------------------

export function ConversationRow({ item, active, onSelect }: { item: InboxItem; active: boolean; onSelect: () => void }) {
    const unread = item.unread > 0 && !item.pending;

    return (
        <button
            type="button"
            onClick={onSelect}
            className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors ${active ? 'bg-primary/10' : 'hover:bg-muted/60'}`}
        >
            <PersonAvatar person={item.other} size={46} />
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-1.5">
                    <span className={`truncate text-sm ${unread ? 'font-bold' : 'font-medium'}`}>{item.other.name}</span>
                    {item.other.organizer && <BadgeCheck className="size-3.5 shrink-0 text-primary" aria-label="Organizer" />}
                    {item.last && <span className={`ml-auto shrink-0 text-[11px] ${unread ? 'font-semibold text-primary' : 'text-muted-foreground'}`}>{shortTime(item.last.at)}</span>}
                </span>
                <span className="mt-0.5 flex items-center gap-1.5">
                    <span className={`truncate text-xs ${unread ? 'font-semibold text-foreground' : 'text-muted-foreground'}`}>
                        {item.blocked ? <span className="inline-flex items-center gap-1"><Ban className="size-3" /> Blocked</span>
                            : item.pending ? <span className="inline-flex items-center gap-1"><Clock className="size-3" /> Request sent</span>
                                : item.last ? <>{item.last.mine && 'You: '}{item.last.image && <ImageIcon className="mr-0.5 inline size-3 align-[-2px]" />}{item.last.text}</> : ''}
                    </span>
                    {unread && <span className="ml-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-bold text-primary-foreground">{item.unread > 99 ? '99+' : item.unread}</span>}
                </span>
            </span>
        </button>
    );
}

// ---- messages ----------------------------------------------------------------------

export function DaySeparator({ label }: { label: string }) {
    return (
        <div className="my-3 flex items-center gap-3 text-[11px] font-medium text-muted-foreground">
            <span className="h-px flex-1 bg-border" />{label}<span className="h-px flex-1 bg-border" />
        </div>
    );
}

export function TypingDots() {
    return (
        <div className="flex w-fit items-center gap-1 rounded-2xl rounded-bl-md bg-muted px-4 py-3" aria-label="Typing">
            {[0, 1, 2].map((i) => (
                <span key={i} className="size-1.5 animate-bounce rounded-full bg-muted-foreground/70" style={{ animationDelay: `${i * 0.15}s` }} />
            ))}
        </div>
    );
}

/** One message. `tail` = last of a run from the same sender (shows time + receipt). */
export function Bubble({ m, tail, seen, onImage, onRetry, onUnsend }: {
    m: ChatMessage; tail: boolean; seen: boolean;
    onImage: (src: string) => void; onRetry: () => void; onUnsend?: () => void;
}) {
    const mine = m.mine;

    if (m.deleted) {
        return (
            <div className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
                <span className="rounded-2xl border border-dashed border-border px-3 py-1.5 text-xs italic text-muted-foreground">
                    {m.removed_by_admin ? 'Removed by a moderator' : mine ? 'You removed this message' : 'Message removed'}
                </span>
            </div>
        );
    }

    const image = m.localImage ?? m.image?.thumb;
    const ratio = m.image && m.image.w && m.image.h ? m.image.w / m.image.h : 4 / 3;

    return (
        <div className={`group flex flex-col ${mine ? 'items-end' : 'items-start'}`}>
            <div className={`flex max-w-[85%] items-end gap-1 sm:max-w-[70%] ${mine ? 'flex-row-reverse' : ''}`}>
                <div
                    className={`overflow-hidden text-sm leading-relaxed shadow-sm ${mine ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground'} ${tail ? (mine ? 'rounded-2xl rounded-br-md' : 'rounded-2xl rounded-bl-md') : 'rounded-2xl'} ${m.state === 'sending' ? 'opacity-70' : ''} ${m.state === 'failed' ? 'ring-2 ring-destructive/60' : ''}`}
                >
                    {image && (
                        <button type="button" onClick={() => m.image && onImage(m.image.full)} className="block w-[min(260px,70vw)]" style={{ aspectRatio: ratio }}>
                            <img src={image} alt="Photo" className="size-full object-cover" loading="lazy" />
                        </button>
                    )}
                    {m.body && <p className="whitespace-pre-wrap break-words px-3.5 py-2 [overflow-wrap:anywhere]">{m.body}</p>}
                </div>
                {mine && onUnsend && !m.state && (
                    <button type="button" onClick={onUnsend} className="mb-1 hidden rounded-full p-1 text-[10px] text-muted-foreground hover:bg-muted group-hover:block" title="Remove for everyone">Unsend</button>
                )}
            </div>
            {m.state === 'failed' ? (
                <button type="button" onClick={onRetry} className="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-destructive">
                    <AlertCircle className="size-3" /> {m.error ?? 'Not sent'} · Tap to retry
                </button>
            ) : tail && (
                <span className="mt-1 inline-flex items-center gap-1 text-[10px] text-muted-foreground">
                    {new Date(m.at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}
                    {mine && (m.state === 'sending' ? <Clock className="size-3" /> : seen ? <span className="inline-flex items-center gap-0.5 text-primary"><CheckCheck className="size-3.5" /> Seen</span> : <Check className="size-3.5" />)}
                </span>
            )}
        </div>
    );
}
