import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Bell, Check, Users } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Entry { id: number; name: string; email: string; status: string; joined: string | null; notified_at: string | null }
interface EventInfo { title: string; slug: string }

export default function Waitlist({ event, entries, waiting }: { event: EventInfo; entries: Entry[]; waiting: number }) {
    const notify = (e: Entry) => router.post(`/host/events/${event.slug}/waitlist/${e.id}/notify`, {}, { preserveScroll: true });
    const notifyAll = () => router.post(`/host/events/${event.slug}/waitlist/notify-all`, {}, { preserveScroll: true });

    return (
        <>
            <Head title={`Waitlist · ${event.title}`} />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <Link href="/host/events" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Events</Link>
                <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Waitlist</h1>
                        <p className="text-sm text-muted-foreground">{event.title} — {waiting} waiting for a spot.</p>
                    </div>
                    {waiting > 0 && <Button onClick={notifyAll}><Bell className="size-4" /> Invite everyone waiting</Button>}
                </div>

                {entries.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border py-16 text-center">
                        <span className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"><Users className="size-5" /></span>
                        <p className="mt-3 text-sm font-medium">No one on the waitlist yet</p>
                        <p className="mt-1 text-xs text-muted-foreground">When your event sells out, people can join the waitlist from its page.</p>
                    </div>
                ) : (
                    <ul className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                        {entries.map((e) => (
                            <li key={e.id} className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3 last:border-0">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="truncate text-sm font-semibold">{e.name}</span>
                                        {e.status === 'notified' && <Badge variant="secondary"><Check className="size-3" /> Invited{e.notified_at ? ` · ${e.notified_at}` : ''}</Badge>}
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground">{e.email} · joined {e.joined}</div>
                                </div>
                                {e.status === 'waiting'
                                    ? <Button size="sm" variant="outline" onClick={() => notify(e)}><Bell className="size-3.5" /> Invite</Button>
                                    : <Button size="sm" variant="ghost" onClick={() => notify(e)}>Re-invite</Button>}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Waitlist.layout = { breadcrumbs: [{ title: 'Waitlist', href: '#' }] };
