import { Head, Link, router } from '@inertiajs/react';
import { ExternalLink, Search } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Row { title: string; slug: string; organizer: string | null; status: string; sold: number; revenue: number }
interface Paginated { data: Row[]; prev_page_url: string | null; next_page_url: string | null }

export default function AdminEvents({ events, filters }: { events: Paginated; filters: { q: string } }) {
    const [q, setQ] = useState(filters.q);

    return (
        <>
            <Head title="All events" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <h1 className="mb-6 text-2xl font-bold tracking-tight">All events</h1>

                <form onSubmit={(e) => {
 e.preventDefault(); router.get('/admin/all-events', q ? { q } : {}, { preserveState: true }); 
}} className="mb-5 flex max-w-md gap-2">
                    <label className="flex h-10 flex-1 items-center gap-2 rounded-lg border border-input bg-card px-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input className="w-full bg-transparent text-sm outline-none" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search events" />
                    </label>
                    <Button type="submit">Search</Button>
                </form>

                <div className="overflow-hidden rounded-xl border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr><th className="px-4 py-3 font-medium">Event</th><th className="px-4 py-3 font-medium">Organizer</th><th className="px-4 py-3 font-medium">Status</th><th className="px-4 py-3 font-medium">Sold</th><th className="px-4 py-3 font-medium">Revenue</th><th className="px-4 py-3"></th></tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {events.data.length === 0 ? (
                                <tr><td colSpan={6} className="px-4 py-10 text-center text-sm text-muted-foreground">No events.</td></tr>
                            ) : events.data.map((e) => (
                                <tr key={e.slug} className="hover:bg-muted/30">
                                    <td className="px-4 py-3 font-medium">{e.title}</td>
                                    <td className="px-4 py-3 text-muted-foreground">{e.organizer ?? '—'}</td>
                                    <td className="px-4 py-3"><Badge variant={e.status === 'published' ? 'default' : e.status === 'cancelled' ? 'destructive' : 'secondary'} className="capitalize">{e.status}</Badge></td>
                                    <td className="px-4 py-3 text-muted-foreground">{e.sold}</td>
                                    <td className="px-4 py-3 whitespace-nowrap">RM {e.revenue.toFixed(2)}</td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-1">
                                            <Button asChild variant="outline" size="sm"><Link href={`/admin/all-events/${e.slug}`}>Manage</Link></Button>
                                            {e.status === 'published' && <Button asChild variant="ghost" size="sm"><a href={`/e/${e.slug}`} target="_blank" rel="noreferrer" aria-label="View live"><ExternalLink className="size-3.5" /></a></Button>}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {(events.prev_page_url || events.next_page_url) && (
                    <div className="mt-6 flex justify-between">
                        <Button asChild variant="outline" disabled={!events.prev_page_url}>{events.prev_page_url ? <Link href={events.prev_page_url}>← Previous</Link> : <span>← Previous</span>}</Button>
                        <Button asChild variant="outline" disabled={!events.next_page_url}>{events.next_page_url ? <Link href={events.next_page_url}>Next →</Link> : <span>Next →</span>}</Button>
                    </div>
                )}
            </div>
        </>
    );
}

AdminEvents.layout = {
    breadcrumbs: [{ title: 'All events', href: '/admin/all-events' }],
};
