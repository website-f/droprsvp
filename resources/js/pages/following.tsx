import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, Compass, UserRoundCheck, Users } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface Organizer { id: number; name: string; followers: number }
interface UpcomingEvent { slug: string; title: string; organizer: string | null; cover_image: string | null; when: string | null }
interface Props { organizers: Organizer[]; upcoming: UpcomingEvent[] }

const TINTS = ['#6c63ff', '#2ec4b6', '#f5a524', '#ff6584', '#3b82f6', '#a855f7'];
const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';

export default function Following({ organizers, upcoming }: Props) {
    const unfollow = (id: number) => router.post(`/organizers/${id}/follow`, {}, { preserveScroll: true });

    return (
        <>
            <Head title="Following" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight">Following</h1>
                    <p className="text-sm text-muted-foreground">Organizers you follow and their upcoming events.</p>
                </div>

                {organizers.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border py-16 text-center">
                        <UserRoundCheck className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">You're not following any organizers yet.</p>
                        <Button asChild><Link href="/en-my/all"><Compass className="size-4" /> Discover events</Link></Button>
                    </div>
                ) : (
                    <div className="grid gap-6">
                        {/* Organizers */}
                        <section>
                            <h2 className="mb-3 text-sm font-semibold text-muted-foreground">Organizers ({organizers.length})</h2>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {organizers.map((o, i) => (
                                    <div key={o.id} className="flex items-center gap-3 rounded-xl border border-border bg-card p-3">
                                        <span className="flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white" style={{ backgroundColor: TINTS[i % TINTS.length] }}>{initials(o.name)}</span>
                                        <div className="min-w-0 flex-1">
                                            <Link href={`/o/${o.id}`} className="truncate font-medium hover:underline">{o.name}</Link>
                                            <div className="flex items-center gap-1 text-xs text-muted-foreground"><Users className="size-3" /> {o.followers} follower{o.followers === 1 ? '' : 's'}</div>
                                        </div>
                                        <Button variant="outline" size="sm" onClick={() => unfollow(o.id)}>Following</Button>
                                    </div>
                                ))}
                            </div>
                        </section>

                        {/* Upcoming from them */}
                        <section>
                            <h2 className="mb-3 text-sm font-semibold text-muted-foreground">Upcoming events</h2>
                            {upcoming.length === 0 ? (
                                <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">Nothing upcoming from the organizers you follow.</p>
                            ) : (
                                <div className="grid gap-3">
                                    {upcoming.map((e) => (
                                        <Link key={e.slug} href={`/en-my/e/${e.slug}`} className="flex items-center gap-4 rounded-xl border border-border bg-card p-3 transition-colors hover:border-foreground/30">
                                            <div className="size-16 shrink-0 overflow-hidden rounded-lg bg-muted">
                                                {e.cover_image
                                                    ? <img src={e.cover_image} alt={e.title} className="size-full object-cover" />
                                                    : <div className="flex size-full items-center justify-center text-muted-foreground"><CalendarDays className="size-6" /></div>}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate font-semibold">{e.title}</div>
                                                <div className="text-xs text-muted-foreground">{e.organizer}</div>
                                                {e.when && <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"><CalendarDays className="size-3" /> {e.when}</div>}
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>
                )}
            </div>
        </>
    );
}

Following.layout = {
    breadcrumbs: [{ title: 'Following', href: '/following' }],
};
