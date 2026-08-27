import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { CalendarDays, MapPin, Search } from 'lucide-react';

interface Card { slug: string; title: string; cover_image: string | null; category: string | null; when: string | null; venue: string | null; from_price: number | null; has_free: boolean }
interface Paginated { data: Card[]; prev_page_url: string | null; next_page_url: string | null }
interface Category { name: string; slug: string }
interface Seo { title: string }

function priceLabel(c: Card): string {
    if (c.from_price !== null) return `From RM ${c.from_price.toFixed(2)}`;
    if (c.has_free) return 'Free';
    return '';
}

export default function Discover({ events, categories, filters, seo }: { events: Paginated; categories: Category[]; filters: { q: string; category: string }; seo: Seo }) {
    const [q, setQ] = useState(filters.q);

    const go = (params: Record<string, string>) => router.get('/events', { q, category: filters.category, ...params }, { preserveScroll: true, preserveState: true });

    return (
        <>
            {/* SEO is server-rendered by Laravel; keep only the tab <title>. */}
            <Head title={seo.title} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
                    <h1 className="text-3xl font-bold tracking-tight">Browse events</h1>

                    {/* Search */}
                    <form onSubmit={(e) => { e.preventDefault(); go({}); }} className="mt-6 flex max-w-xl gap-2">
                        <label className="flex h-11 flex-1 items-center gap-2 rounded-full border border-border bg-card px-4">
                            <Search className="size-4 shrink-0 text-muted-foreground" />
                            <input className="w-full bg-transparent text-sm outline-none" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search events" />
                        </label>
                        <Button type="submit" className="h-11">Search</Button>
                    </form>

                    {/* Category filter */}
                    <div className="mt-5 flex flex-wrap gap-2">
                        <button onClick={() => go({ category: '' })} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${filters.category === '' ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>All</button>
                        {categories.map((c) => (
                            <button key={c.slug} onClick={() => go({ category: c.slug })} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${filters.category === c.slug ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>{c.name}</button>
                        ))}
                    </div>

                    {/* Results */}
                    {events.data.length === 0 ? (
                        <p className="mt-12 text-center text-sm text-muted-foreground">No events found. Try a different search or category.</p>
                    ) : (
                        <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {events.data.map((e) => (
                                <Link key={e.slug} href={`/e/${e.slug}`} className="group overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-colors hover:border-foreground/30">
                                    <div className="aspect-[16/9] w-full overflow-hidden bg-muted">
                                        {e.cover_image
                                            ? <img src={e.cover_image} alt={e.title} className="size-full object-cover" />
                                            : <div className="flex size-full items-center justify-center text-muted-foreground"><CalendarDays className="size-8" /></div>}
                                    </div>
                                    <div className="p-4">
                                        {e.category && <Badge variant="secondary" className="mb-2">{e.category}</Badge>}
                                        <h2 className="line-clamp-2 font-semibold leading-snug group-hover:underline">{e.title}</h2>
                                        <div className="mt-2 grid gap-1 text-xs text-muted-foreground">
                                            {e.when && <span className="flex items-center gap-1.5"><CalendarDays className="size-3.5" /> {e.when}</span>}
                                            {e.venue && <span className="flex items-center gap-1.5"><MapPin className="size-3.5" /> {e.venue}</span>}
                                        </div>
                                        {priceLabel(e) && <div className="mt-3 text-sm font-semibold">{priceLabel(e)}</div>}
                                    </div>
                                </Link>
                            ))}
                        </div>
                    )}

                    {(events.prev_page_url || events.next_page_url) && (
                        <div className="mt-10 flex justify-between">
                            <Button asChild variant="outline" disabled={!events.prev_page_url}>{events.prev_page_url ? <Link href={events.prev_page_url}>← Previous</Link> : <span>← Previous</span>}</Button>
                            <Button asChild variant="outline" disabled={!events.next_page_url}>{events.next_page_url ? <Link href={events.next_page_url}>Next →</Link> : <span>Next →</span>}</Button>
                        </div>
                    )}
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
