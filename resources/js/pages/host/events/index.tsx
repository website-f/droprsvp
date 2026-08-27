import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { CalendarDays, Pencil, Plus, Ticket, Trash2 } from 'lucide-react';

interface HostEvent {
    id: number;
    title: string;
    slug: string;
    status: string;
    visibility: string;
    starts_at: string | null;
    ticket_types_count: number;
    sessions_count: number;
    orders_count: number;
}

function statusTone(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'published') return 'default';
    if (status === 'cancelled') return 'destructive';
    return 'secondary';
}

function formatDate(value: string | null): string {
    if (!value) return 'No date set';
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export default function EventsIndex({ events }: { events: HostEvent[] }) {
    const remove = (e: HostEvent) => {
        if (confirm(`Delete "${e.title}"? This cannot be undone.`)) {
            router.delete(`/host/events/${e.slug}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Events" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Your events</h1>
                        <p className="text-sm text-muted-foreground">Create and manage the events you host.</p>
                    </div>
                    <Button asChild>
                        <Link href="/host/events/create"><Plus className="size-4" /> Create event</Link>
                    </Button>
                </div>

                {events.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border py-16 text-center">
                        <CalendarDays className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">You haven't created any events yet.</p>
                        <Button asChild><Link href="/host/events/create"><Plus className="size-4" /> Create your first event</Link></Button>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Event</th>
                                    <th className="px-4 py-3 font-medium">Status</th>
                                    <th className="px-4 py-3 font-medium">When</th>
                                    <th className="px-4 py-3 font-medium">Tickets</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {events.map((e) => (
                                    <tr key={e.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{e.title}</div>
                                            <div className="text-xs text-muted-foreground capitalize">{e.visibility} · {e.sessions_count} session(s) · {e.orders_count} order(s)</div>
                                        </td>
                                        <td className="px-4 py-3"><Badge variant={statusTone(e.status)} className="capitalize">{e.status}</Badge></td>
                                        <td className="px-4 py-3 text-muted-foreground">{formatDate(e.starts_at)}</td>
                                        <td className="px-4 py-3"><span className="inline-flex items-center gap-1 text-muted-foreground"><Ticket className="size-3.5" /> {e.ticket_types_count}</span></td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2">
                                                <Button asChild variant="outline" size="sm"><Link href={`/host/events/${e.slug}/edit`}><Pencil className="size-3.5" /> Edit</Link></Button>
                                                <Button variant="ghost" size="sm" onClick={() => remove(e)}><Trash2 className="size-3.5" /></Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

EventsIndex.layout = {
    breadcrumbs: [{ title: 'Events', href: '/host/events' }],
};
