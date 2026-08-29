import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, MapPin, Star, UserCheck, UserPlus, Users } from 'lucide-react';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { Button } from '@/components/ui/button';

interface EventCard {
    slug: string; title: string; cover_image: string | null; when: string | null; venue: string | null;
    from_price: number | null; has_free: boolean; participants: number; rating: number | null; is_past: boolean;
}
interface Organizer { id: number; name: string; followers: number; events_count: number; joined: string | null }
interface Viewer { authed: boolean; is_self: boolean; is_following: boolean }

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';

function priceLabel(e: EventCard): string {
    if (e.from_price !== null) {
        return `From RM ${e.from_price.toFixed(2)}`;
    }

    return e.has_free ? 'Free' : '';
}

function EventGrid({ events }: { events: EventCard[] }) {
    return (
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {events.map((e) => (
                <Link key={e.slug} href={`/en-my/e/${e.slug}`} className="group overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-colors hover:border-foreground/30">
                    <div className="relative aspect-[16/9] w-full overflow-hidden bg-muted">
                        {e.cover_image
                            ? <img src={e.cover_image} alt={e.title} className={`size-full object-cover ${e.is_past ? 'opacity-70 grayscale' : ''}`} />
                            : <div className="flex size-full items-center justify-center text-muted-foreground"><CalendarDays className="size-8" /></div>}
                        {e.is_past && <span className="absolute left-2 top-2 rounded-full bg-foreground/80 px-2 py-0.5 text-[11px] font-semibold text-background">Past</span>}
                    </div>
                    <div className="p-4">
                        <h3 className="line-clamp-2 font-semibold leading-snug group-hover:underline">{e.title}</h3>
                        <div className="mt-2 grid gap-1 text-xs text-muted-foreground">
                            {e.when && <span className="flex items-center gap-1.5"><CalendarDays className="size-3.5" /> {e.when}</span>}
                            {e.venue && <span className="flex items-center gap-1.5"><MapPin className="size-3.5" /> {e.venue}</span>}
                        </div>
                        <div className="mt-3 flex items-center justify-between">
                            {priceLabel(e) && <span className="text-sm font-semibold">{priceLabel(e)}</span>}
                            <span className="flex items-center gap-3 text-xs text-muted-foreground">
                                {e.participants > 0 && <span className="flex items-center gap-1"><Users className="size-3.5" /> {e.participants}</span>}
                                {e.rating !== null && <span className="flex items-center gap-1"><Star className="size-3.5 fill-[#f5a524] text-[#f5a524]" /> {e.rating.toFixed(1)}</span>}
                            </span>
                        </div>
                    </div>
                </Link>
            ))}
        </div>
    );
}

export default function OrganizerProfile({ organizer, upcoming, past, viewer }: { organizer: Organizer; upcoming: EventCard[]; past: EventCard[]; viewer: Viewer }) {
    const follow = () => router.post(`/organizers/${organizer.id}/follow`, {}, { preserveScroll: true });

    return (
        <>
            <Head title={`${organizer.name} — DropRSVP`} />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                {/* Header */}
                <section className="border-b border-border bg-muted/30">
                    <div className="mx-auto flex w-full max-w-5xl flex-col items-center gap-4 px-6 py-12 text-center sm:flex-row sm:items-center sm:gap-6 sm:text-left">
                        <span className="flex size-20 shrink-0 items-center justify-center rounded-full bg-foreground text-2xl font-bold text-background">{initials(organizer.name)}</span>
                        <div className="min-w-0 flex-1">
                            <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">{organizer.name}</h1>
                            <div className="mt-2 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-sm text-muted-foreground sm:justify-start">
                                <span className="flex items-center gap-1.5"><CalendarDays className="size-4" /> {organizer.events_count} event{organizer.events_count === 1 ? '' : 's'}</span>
                                <span className="flex items-center gap-1.5"><Users className="size-4" /> {organizer.followers} follower{organizer.followers === 1 ? '' : 's'}</span>
                                {organizer.joined && <span>Since {organizer.joined}</span>}
                            </div>
                        </div>
                        {!viewer.is_self && (
                            <div className="shrink-0">
                                {viewer.authed ? (
                                    <Button variant={viewer.is_following ? 'outline' : 'default'} onClick={follow}>
                                        {viewer.is_following ? <><UserCheck className="size-4" /> Following</> : <><UserPlus className="size-4" /> Follow</>}
                                    </Button>
                                ) : (
                                    <Button asChild><Link href="/login"><UserPlus className="size-4" /> Follow</Link></Button>
                                )}
                            </div>
                        )}
                    </div>
                </section>

                <main className="mx-auto w-full max-w-5xl flex-1 px-6 py-10">
                    {organizer.events_count === 0 ? (
                        <p className="rounded-2xl border border-dashed border-border py-16 text-center text-sm text-muted-foreground">No public events yet.</p>
                    ) : (
                        <div className="grid gap-10">
                            {upcoming.length > 0 && (
                                <section>
                                    <h2 className="mb-4 text-lg font-bold tracking-tight">Upcoming events</h2>
                                    <EventGrid events={upcoming} />
                                </section>
                            )}
                            {past.length > 0 && (
                                <section>
                                    <h2 className="mb-4 text-lg font-bold tracking-tight">Past events</h2>
                                    <EventGrid events={past} />
                                </section>
                            )}
                        </div>
                    )}
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
