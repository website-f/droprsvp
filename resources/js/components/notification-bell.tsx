import { router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useState } from 'react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { postJson } from '@/lib/api';

interface Item { id: number; type: string; title: string; body: string | null; url: string | null; level: string; read: boolean; when: string | null }

/** Bell inbox for signed-in users. Lazy-loads on open; badge from a shared prop. */
export function NotificationBell() {
    const initial = (usePage().props.auth as { unread_notifications?: number } | undefined)?.unread_notifications ?? 0;
    const [unread, setUnread] = useState(initial);
    const [items, setItems] = useState<Item[]>([]);
    const [loading, setLoading] = useState(false);

    const load = async () => {
        setLoading(true);

        try {
            const res = await fetch('/notifications', { headers: { Accept: 'application/json' } });

            if (res.ok) {
                const data = (await res.json()) as { unread: number; items: Item[] };
                setItems(data.items);
                setUnread(data.unread);
            }
        } finally {
            setLoading(false);
        }
    };

    const markAll = async () => {
        setUnread(0);
        setItems((p) => p.map((i) => ({ ...i, read: true })));
        await postJson('/notifications/read');
    };

    const open = (n: Item) => {
        if (!n.read) {
            setUnread((u) => Math.max(0, u - 1));
            postJson(`/notifications/${n.id}/read`);
        }

        if (n.url) {
            router.visit(n.url);
        }
    };

    return (
        <DropdownMenu onOpenChange={(o) => o && load()}>
            <DropdownMenuTrigger asChild>
                <button type="button" aria-label="Notifications" className="relative flex size-10 items-center justify-center rounded-lg border border-border transition-colors hover:bg-accent">
                    <Bell className="size-5" />
                    {unread > 0 && (
                        <span className="absolute -right-1 -top-1 flex min-w-[1.1rem] items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold leading-4 text-primary-foreground">{unread > 9 ? '9+' : unread}</span>
                    )}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80 p-0">
                <div className="flex items-center justify-between border-b border-border px-3 py-2">
                    <span className="text-sm font-semibold">Notifications</span>
                    {unread > 0 && <button type="button" onClick={markAll} className="text-xs text-muted-foreground hover:text-foreground">Mark all read</button>}
                </div>
                <div className="max-h-96 overflow-y-auto">
                    {loading && items.length === 0 && <p className="px-3 py-6 text-center text-sm text-muted-foreground">Loading…</p>}
                    {!loading && items.length === 0 && <p className="px-3 py-6 text-center text-sm text-muted-foreground">You’re all caught up.</p>}
                    {items.map((n) => (
                        <button key={n.id} type="button" onClick={() => open(n)} className={`flex w-full flex-col items-start gap-0.5 border-b border-border/60 px-3 py-2.5 text-left last:border-0 hover:bg-accent ${n.read ? '' : 'bg-primary/5'}`}>
                            <div className="flex w-full items-center gap-2">
                                {!n.read && <span className="size-1.5 shrink-0 rounded-full bg-primary" />}
                                <span className="flex-1 text-sm font-medium">{n.title}</span>
                                {n.when && <span className="shrink-0 text-[11px] text-muted-foreground">{n.when}</span>}
                            </div>
                            {n.body && <span className="line-clamp-2 text-xs text-muted-foreground">{n.body}</span>}
                        </button>
                    ))}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
