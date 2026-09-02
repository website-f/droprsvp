import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, Globe, Images, Info, MapPin, Sparkles, Star, UserCheck, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface EventCard {
    slug: string; title: string; cover_image: string | null; when: string | null; venue: string | null;
    from_price: number | null; has_free: boolean; participants: number; rating: number | null; is_past: boolean;
}
interface Organizer {
    id: number; slug: string; name: string; avatar: string | null; bio: string | null; website: string | null;
    location: string | null; event_types: string[]; followers: number; members: number; events_count: number; joined: string | null;
}
interface Members { attendees: { name: string }[]; followers: { name: string }[] }
interface Photo { path: string; caption: string | null }
interface Viewer { authed: boolean; is_self: boolean; is_following: boolean }
type Tab = 'about' | 'events' | 'members' | 'photos';

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
const TINTS = ['#6c63ff', '#2ec4b6', '#f5a524', '#3b82f6', '#ff6584', '#a855f7'];

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
                <Link key={e.slug} href={`/en-my/e/${e.slug}`} className="group overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-all hover:-translate-y-0.5 hover:border-foreground/30 hover:shadow-md">
                    <div className="relative aspect-[16/9] w-full overflow-hidden bg-muted">
                        {e.cover_image
                            ? <img src={e.cover_image} alt={e.title} loading="lazy" className={`size-full object-cover ${e.is_past ? 'opacity-70 grayscale' : ''}`} />
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

function MemberGrid({ people, empty }: { people: { name: string }[]; empty: string }) {
    if (people.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            {people.map((p, i) => (
                <div key={i} className="flex items-center gap-2.5 rounded-xl border border-border bg-card p-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" style={{ backgroundColor: TINTS[i % TINTS.length] }}>{initials(p.name)}</span>
                    <span className="min-w-0 truncate text-sm font-medium">{p.name}</span>
                </div>
            ))}
        </div>
    );
}

export default function OrganizerProfile({ organizer, upcoming, past, members, photos, similar, viewer }: {
    organizer: Organizer; upcoming: EventCard[]; past: EventCard[]; members: Members; photos: Photo[]; similar: EventCard[]; viewer: Viewer;
}) {
    const [tab, setTab] = useState<Tab>('events');
    const follow = () => (viewer.authed
        ? router.post(`/organizers/${organizer.id}/follow`, {}, { preserveScroll: true })
        : router.visit('/login'));

    const TABS: { key: Tab; label: string; icon: typeof Info; count?: number }[] = [
        { key: 'about', label: 'About', icon: Info },
        { key: 'events', label: 'Events', icon: CalendarDays, count: organizer.events_count },
        { key: 'members', label: 'Members', icon: Users, count: organizer.members + organizer.followers },
        { key: 'photos', label: 'Photos', icon: Images, count: photos.length },
    ];

    return (
        <>
            <Head title={`${organizer.name} — DropRSVP`} />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                {/* Header */}
                <section className="border-b border-border bg-muted/30">
                    <div className="mx-auto flex w-full max-w-6xl flex-col gap-5 px-6 py-10 sm:flex-row sm:items-start">
                        {organizer.avatar
                            ? <img src={organizer.avatar} alt={organizer.name} className="size-24 shrink-0 rounded-2xl border border-border object-cover" />
                            : <span className="flex size-24 shrink-0 items-center justify-center rounded-2xl bg-foreground text-3xl font-bold text-background">{initials(organizer.name)}</span>}

                        <div className="min-w-0 flex-1">
                            <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">{organizer.name}</h1>
                            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                {organizer.location && <span className="flex items-center gap-1.5"><MapPin className="size-4" /> {organizer.location}</span>}
                                <span className="flex items-center gap-1.5"><Users className="size-4" /> <strong className="text-foreground">{organizer.members}</strong> member{organizer.members === 1 ? '' : 's'}</span>
                                <span className="flex items-center gap-1.5"><UserCheck className="size-4" /> <strong className="text-foreground">{organizer.followers}</strong> follower{organizer.followers === 1 ? '' : 's'}</span>
                                <span className="flex items-center gap-1.5"><CalendarDays className="size-4" /> <strong className="text-foreground">{organizer.events_count}</strong> event{organizer.events_count === 1 ? '' : 's'}</span>
                                {organizer.joined && <span>Since {organizer.joined}</span>}
                            </div>
                            {organizer.bio && <p className="mt-3 max-w-2xl text-sm leading-relaxed text-foreground/80 line-clamp-2">{organizer.bio}</p>}
                            {organizer.event_types.length > 0 && (
                                <div className="mt-3 flex flex-wrap gap-1.5">
                                    {organizer.event_types.slice(0, 6).map((t) => <Badge key={t} variant="secondary" className="capitalize">{t}</Badge>)}
                                </div>
                            )}
                        </div>

                        {!viewer.is_self && (
                            <Button variant={viewer.is_following ? 'outline' : 'default'} onClick={follow} className="shrink-0">
                                {viewer.is_following ? <><UserCheck className="size-4" /> Following</> : <><UserPlus className="size-4" /> Follow</>}
                            </Button>
                        )}
                    </div>

                    {/* Tabs */}
                    <div className="mx-auto flex w-full max-w-6xl gap-1 overflow-x-auto px-4">
                        {TABS.map((t) => {
                            const active = tab === t.key;

                            return (
                                <button key={t.key} type="button" onClick={() => setTab(t.key)}
                                    className={`flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-medium transition-colors ${active ? 'border-foreground text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'}`}>
                                    <t.icon className="size-4" /> {t.label}
                                    {t.count !== undefined && t.count > 0 && <span className="rounded-full bg-muted px-1.5 text-xs">{t.count}</span>}
                                </button>
                            );
                        })}
                    </div>
                </section>

                <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
                    {tab === 'about' && (
                        <div className="grid gap-6 lg:grid-cols-[1.6fr_1fr]">
                            <div className="rounded-2xl border border-border bg-card p-6">
                                <h2 className="text-lg font-bold tracking-tight">About</h2>
                                {organizer.bio
                                    ? <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-foreground/80">{organizer.bio}</p>
                                    : <p className="mt-3 text-sm text-muted-foreground">This organizer hasn’t added a description yet.</p>}
                                {organizer.event_types.length > 0 && (
                                    <div className="mt-5">
                                        <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">What they host</div>
                                        <div className="mt-2 flex flex-wrap gap-1.5">
                                            {organizer.event_types.map((t) => <Badge key={t} variant="secondary" className="capitalize">{t}</Badge>)}
                                        </div>
                                    </div>
                                )}
                            </div>
                            <div className="grid content-start gap-3 rounded-2xl border border-border bg-card p-6 text-sm">
                                <div className="flex items-center justify-between"><span className="text-muted-foreground">Members</span><strong>{organizer.members}</strong></div>
                                <div className="flex items-center justify-between"><span className="text-muted-foreground">Followers</span><strong>{organizer.followers}</strong></div>
                                <div className="flex items-center justify-between"><span className="text-muted-foreground">Events hosted</span><strong>{organizer.events_count}</strong></div>
                                {organizer.joined && <div className="flex items-center justify-between"><span className="text-muted-foreground">On DropRSVP since</span><strong>{organizer.joined}</strong></div>}
                                {organizer.website && <a href={organizer.website} target="_blank" rel="noopener" className="mt-1 flex items-center gap-1.5 font-medium text-foreground underline underline-offset-2"><Globe className="size-4" /> Website</a>}
                            </div>
                        </div>
                    )}

                    {tab === 'events' && (
                        organizer.events_count === 0 ? (
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
                        )
                    )}

                    {tab === 'members' && (
                        <div className="grid gap-10">
                            <section>
                                <h2 className="mb-1 text-lg font-bold tracking-tight">Attendees</h2>
                                <p className="mb-4 text-sm text-muted-foreground">People who’ve joined events by {organizer.name}.</p>
                                <MemberGrid people={members.attendees} empty="No attendees yet." />
                            </section>
                            <section>
                                <h2 className="mb-1 text-lg font-bold tracking-tight">Followers</h2>
                                <p className="mb-4 text-sm text-muted-foreground">People following {organizer.name} for updates.</p>
                                <MemberGrid people={members.followers} empty="No followers yet." />
                            </section>
                        </div>
                    )}

                    {tab === 'photos' && (
                        photos.length === 0 ? (
                            <p className="rounded-2xl border border-dashed border-border py-16 text-center text-sm text-muted-foreground">No photos yet — {organizer.name} hasn’t shared any event photos.</p>
                        ) : (
                            <div className="columns-2 gap-3 sm:columns-3 lg:columns-4 [&>*]:mb-3">
                                {photos.map((ph, i) => (
                                    <img key={i} src={ph.path} alt={ph.caption ?? ''} loading="lazy" className="w-full break-inside-avoid rounded-xl border border-border object-cover" />
                                ))}
                            </div>
                        )
                    )}

                    {/* Similar events */}
                    {similar.length > 0 && (
                        <section className="mt-14 border-t border-border pt-10">
                            <h2 className="mb-1 flex items-center gap-2 text-lg font-bold tracking-tight"><Sparkles className="size-5 text-[#6c63ff]" /> Similar events you might like</h2>
                            <p className="mb-4 text-sm text-muted-foreground">Based on the kinds of events {organizer.name} hosts.</p>
                            <EventGrid events={similar} />
                        </section>
                    )}
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
