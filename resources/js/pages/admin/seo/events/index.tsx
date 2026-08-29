import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Pencil, Search } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Row { slug: string; title: string; status: string; customised: boolean; preview_title: string; preview_desc: string }
interface Paginated { data: Row[]; prev_page_url: string | null; next_page_url: string | null }
interface Props { events: Paginated; filters: { q: string }; baseUrl: string }

export default function EventsSeoIndex({ events, filters, baseUrl }: Props) {
    const [q, setQ] = useState(filters.q);
    const search = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/admin/seo/events', q ? { q } : {}, { preserveState: true, preserveScroll: true });
    };

    return (
        <>
            <Head title="Events SEO" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-2">
                    <h1 className="text-2xl font-bold tracking-tight">Events SEO</h1>
                    <p className="text-sm text-muted-foreground">Tune the search snippet — title, description, keywords and social meta — for every event.</p>
                </div>

                <form onSubmit={search} className="my-5 flex gap-2">
                    <label className="flex h-10 flex-1 items-center gap-2 rounded-lg border border-border bg-card px-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input className="w-full bg-transparent text-sm outline-none" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search events by title…" />
                    </label>
                    <Button type="submit">Search</Button>
                </form>

                {events.data.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">No events found.</p>
                ) : (
                    <ul className="grid gap-3">
                        {events.data.map((e) => (
                            <li key={e.slug} className="rounded-2xl border border-border bg-card p-4 shadow-sm">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="truncate font-semibold">{e.title}</span>
                                            <Badge variant={e.status === 'published' ? 'default' : 'secondary'} className="shrink-0 capitalize">{e.status}</Badge>
                                            {e.customised
                                                ? <Badge variant="outline" className="gap-1"><CheckCircle2 className="size-3" /> Custom SEO</Badge>
                                                : <Badge variant="outline" className="text-muted-foreground">Auto</Badge>}
                                        </div>
                                        {/* Google snippet preview */}
                                        <div className="mt-2 rounded-lg border border-border bg-muted/40 p-3">
                                            <div className="truncate text-xs text-emerald-700 dark:text-emerald-400">{baseUrl}/{e.slug}</div>
                                            <div className="mt-0.5 truncate text-sm text-blue-700 dark:text-blue-400">{e.preview_title}</div>
                                            <div className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{e.preview_desc || 'No description — add one to control the snippet.'}</div>
                                        </div>
                                    </div>
                                    <Button asChild variant="outline" size="sm" className="shrink-0">
                                        <Link href={`/admin/seo/events/${e.slug}`}><Pencil className="size-3.5" /> Edit SEO</Link>
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {(events.prev_page_url || events.next_page_url) && (
                    <div className="mt-8 flex justify-between">
                        <Button asChild variant="outline" disabled={!events.prev_page_url}>{events.prev_page_url ? <Link href={events.prev_page_url}>← Previous</Link> : <span>← Previous</span>}</Button>
                        <Button asChild variant="outline" disabled={!events.next_page_url}>{events.next_page_url ? <Link href={events.next_page_url}>Next →</Link> : <span>Next →</span>}</Button>
                    </div>
                )}
            </div>
        </>
    );
}

EventsSeoIndex.layout = { breadcrumbs: [{ title: 'Events SEO', href: '/admin/seo/events' }] };
