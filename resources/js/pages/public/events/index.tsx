import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, MapPin, Rocket, Star } from 'lucide-react';
import { useState } from 'react';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { SearchAutocomplete } from '@/components/search-autocomplete';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Card { slug: string; title: string; cover_image: string | null; category: string | null; city: string | null; boosted?: boolean; when: string | null; venue: string | null; from_price: number | null; has_free: boolean; participants: number; faces: string[]; rating: number | null; rating_count: number }

const AVATAR_TINTS = ['#6c63ff', '#2ec4b6', '#f5a524', '#ff6584', '#3b82f6'];
const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
interface Paginated { data: Card[]; prev_page_url: string | null; next_page_url: string | null }
interface Category { name: string; slug: string }
interface City { name: string; slug: string }
interface Active { city: string | null; city_name: string | null; category: string | null; category_name: string | null }
interface CategoryContent { name: string; content: string }
interface Seo { title: string }

/** SEO copy block shown at the bottom of the page, truncated with a See more toggle. */
function ContentBlock({ name, content }: CategoryContent) {
    const [open, setOpen] = useState(false);
    const long = content.length > 320;

    return (
        <div className="rounded-2xl border border-border bg-card p-5">
            <h3 className="mb-2 font-semibold">{name}</h3>
            <p className={`whitespace-pre-line text-sm leading-relaxed text-muted-foreground ${!open && long ? 'line-clamp-4' : ''}`}>{content}</p>
            {long && (
                <button type="button" onClick={() => setOpen((v) => !v)} className="mt-2 text-sm font-medium text-foreground underline underline-offset-4">
                    {open ? 'See less' : 'See more'}
                </button>
            )}
        </div>
    );
}

const LOCALE = 'en-my';
const ANY = 'all';

/** Build a discovery path: /en-my, /en-my/{city}, or /en-my/{city|all}/{category}. */
function pathFor(citySlug: string | null, catSlug: string | null): string {
    const segs = [LOCALE];

    if (catSlug) {
        segs.push(citySlug || ANY, catSlug);
    } else if (citySlug) {
        segs.push(citySlug);
    } else {
        segs.push(ANY); // /en-my/all = browse everything (/en-my is the home)
    }

    return '/' + segs.join('/');
}

function priceLabel(c: Card): string {
    if (c.from_price !== null) {
return `From RM ${c.from_price.toFixed(2)}`;
}

    if (c.has_free) {
return 'Free';
}

    return '';
}

export default function Discover({ events, categories, cities, active, filters, seo, categoryContent = [] }: { events: Paginated; categories: Category[]; cities: City[]; active: Active; filters: { q: string; when: string }; seo: Seo; categoryContent?: CategoryContent[] }) {
    const goto = (path: string, keepQuery = true) =>
        router.get(path, keepQuery && filters.q ? { q: filters.q } : {}, { preserveScroll: true });

    const onCity = (slug: string) => goto(pathFor(slug === ANY ? null : slug, active.category));
    const onCategory = (slug: string | null) => goto(pathFor(active.city, slug));

    return (
        <>
            {/* SEO is server-rendered by Laravel; keep only the tab <title>. */}
            <Head title={seo.title} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
                    <h1 className="text-3xl font-bold tracking-tight">{seo.title}</h1>

                    {/* Search + city */}
                    <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <SearchAutocomplete
                            variant="compact"
                            initial={filters.q}
                            placeholder="Search events"
                            onSubmit={(term) => router.get(pathFor(active.city, active.category), term ? { q: term } : {}, { preserveScroll: true })}
                        />
                        <div className="sm:w-56">
                            <AppSelect
                                aria-label="City"
                                value={active.city ?? ANY}
                                onChange={onCity}
                                options={[{ value: ANY, label: 'All cities' }, ...cities.map((c) => ({ value: c.slug, label: c.name }))]}
                            />
                        </div>
                    </div>

                    {/* Category filter — links to SEO paths */}
                    <div className="mt-5 flex flex-wrap gap-2">
                        <button onClick={() => onCategory(null)} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${active.category === null ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>All</button>
                        {categories.map((c) => (
                            <button key={c.slug} onClick={() => onCategory(c.slug)} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${active.category === c.slug ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>{c.name}</button>
                        ))}
                    </div>

                    {/* Results */}
                    {events.data.length === 0 ? (
                        <p className="mt-12 text-center text-sm text-muted-foreground">No events found. Try a different city, category or search.</p>
                    ) : (
                        <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {events.data.map((e) => (
                                <Link key={e.slug} href={`/en-my/e/${e.slug}`} className="group overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-colors hover:border-foreground/30">
                                    <div className="relative aspect-[16/9] w-full overflow-hidden bg-muted">
                                        {e.cover_image
                                            ? <img src={e.cover_image} alt={e.title} className="size-full object-cover" />
                                            : <div className="flex size-full items-center justify-center text-muted-foreground"><CalendarDays className="size-8" /></div>}
                                        {e.boosted && <span className="absolute left-2 top-2 inline-flex items-center gap-1 rounded-full bg-[#6c63ff] px-2 py-0.5 text-[11px] font-semibold text-white shadow"><Rocket className="size-3" /> Promoted</span>}
                                    </div>
                                    <div className="p-4">
                                        {e.category && <Badge variant="secondary" className="mb-2">{e.category}</Badge>}
                                        <h2 className="line-clamp-2 font-semibold leading-snug group-hover:underline">{e.title}</h2>
                                        <div className="mt-2 grid gap-1 text-xs text-muted-foreground">
                                            {e.when && <span className="flex items-center gap-1.5"><CalendarDays className="size-3.5" /> {e.when}</span>}
                                            {(e.venue || e.city) && <span className="flex items-center gap-1.5"><MapPin className="size-3.5" /> {[e.venue, e.city].filter(Boolean).join(' · ')}</span>}
                                        </div>
                                        {priceLabel(e) && <div className="mt-3 text-sm font-semibold">{priceLabel(e)}</div>}

                                        {/* Participants (stacked avatars + total) + rating */}
                                        {(e.participants > 0 || e.rating !== null) && (
                                            <div className="mt-3 flex items-center justify-between gap-3 border-t border-border/60 pt-3 text-xs text-muted-foreground">
                                                {e.participants > 0 ? (
                                                    <span className="flex items-center gap-2">
                                                        <span className="flex -space-x-2">
                                                            {e.faces.map((name, i) => (
                                                                <span key={i} title={name} className="flex size-6 items-center justify-center rounded-full text-[9px] font-bold text-white ring-2 ring-card" style={{ backgroundColor: AVATAR_TINTS[i % AVATAR_TINTS.length] }}>{initials(name)}</span>
                                                            ))}
                                                            <span className="flex size-6 items-center justify-center rounded-full bg-foreground px-1 text-[9px] font-bold text-background ring-2 ring-card">{e.participants > 99 ? '99+' : e.participants}</span>
                                                        </span>
                                                        going
                                                    </span>
                                                ) : <span />}
                                                {e.rating !== null && (
                                                    <span className="flex items-center gap-1"><Star className="size-3.5 fill-[#f5a524] text-[#f5a524]" /> {e.rating.toFixed(1)}<span className="text-muted-foreground/70">({e.rating_count})</span></span>
                                                )}
                                            </div>
                                        )}
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

                    {/* Admin-authored SEO content, per category */}
                    {categoryContent.length > 0 && (
                        <div className="mt-14 grid gap-4 border-t border-border pt-10">
                            {categoryContent.map((c) => <ContentBlock key={c.name} name={c.name} content={c.content} />)}
                        </div>
                    )}
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
