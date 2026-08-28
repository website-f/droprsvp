import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, CheckCircle2, Compass, Plus, Store, Ticket, Wallet } from 'lucide-react';
import { EventCalendar  } from '@/components/event-calendar';
import type {CalendarEvent} from '@/components/event-calendar';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

interface Props {
    stats: { events: number; published: number; tickets_sold: number; checked_in: number; revenue: number };
    sales_by_day: Array<{ label: string; total: number }>;
    upcoming: Array<{ title: string; slug: string; when: string | null; sold: number }>;
    calendar: CalendarEvent[];
    recent_orders: Array<{ reference: string; buyer: string | null; event: string | null; total: number; at: string | null }>;
}

function StatCard({ icon: Icon, label, value }: { icon: React.ComponentType<{ className?: string }>; label: string; value: string }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center gap-2 text-xs uppercase tracking-wide text-muted-foreground"><Icon className="size-4" /> {label}</div>
            <div className="mt-2 text-2xl font-bold tabular-nums">{value}</div>
        </div>
    );
}

export default function Dashboard({ stats, sales_by_day, upcoming, calendar, recent_orders }: Props) {
    const peak = Math.max(1, ...sales_by_day.map((d) => d.total));
    const { auth } = usePage().props;
    const isOrganizer = auth?.is_organizer;

    if (stats.events === 0) {
        // Free attendee accounts don't host — point them at discovery + a clear
        // path to become a vendor. Organizers get the create-event nudge.
        if (!isOrganizer) {
            return (
                <>
                    <Head title="Dashboard" />
                    <div className="mx-auto flex max-w-2xl flex-1 flex-col items-center justify-center gap-4 p-10 text-center">
                        <Compass className="size-10 text-muted-foreground" />
                        <h1 className="text-2xl font-bold tracking-tight">Find your next event</h1>
                        <p className="text-sm text-muted-foreground">Browse events and grab tickets — they’ll show up under My tickets, ready to re-download anytime.</p>
                        <div className="flex flex-wrap items-center justify-center gap-2">
                            <Button asChild><Link href="/en-my"><Compass className="size-4" /> Browse events</Link></Button>
                            <Button asChild variant="outline"><Link href="/my/tickets"><Ticket className="size-4" /> My tickets</Link></Button>
                        </div>
                        <div className="mt-4 rounded-xl border border-border bg-muted/40 p-4 text-sm">
                            <p className="font-medium text-foreground">Want to host &amp; sell tickets?</p>
                            <p className="mt-0.5 text-muted-foreground">Become a vendor to create events, take payments and get payouts.</p>
                            <Button
                                variant="secondary"
                                className="mt-3"
                                onClick={() => router.post('/become-a-vendor')}
                            >
                                <Store className="size-4" /> Become a vendor
                            </Button>
                        </div>
                    </div>
                </>
            );
        }

        return (
            <>
                <Head title="Dashboard" />
                <div className="mx-auto flex max-w-2xl flex-1 flex-col items-center justify-center gap-4 p-10 text-center">
                    <CalendarDays className="size-10 text-muted-foreground" />
                    <h1 className="text-2xl font-bold tracking-tight">Welcome to DropRSVP</h1>
                    <p className="text-sm text-muted-foreground">Create your first event to start selling tickets and see your numbers here.</p>
                    <Button asChild><Link href="/host/events/create"><Plus className="size-4" /> Create an event</Link></Button>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Dashboard" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
                    <Button asChild><Link href="/host/events/create"><Plus className="size-4" /> Create event</Link></Button>
                </div>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard icon={Wallet} label="Revenue" value={`RM ${stats.revenue.toFixed(2)}`} />
                    <StatCard icon={Ticket} label="Tickets sold" value={String(stats.tickets_sold)} />
                    <StatCard icon={CheckCircle2} label="Checked in" value={String(stats.checked_in)} />
                    <StatCard icon={CalendarDays} label="Events" value={`${stats.published}/${stats.events}`} />
                </div>

                {/* Revenue chart */}
                <div className="mt-6 rounded-xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Revenue · last 14 days</h2>
                    <div className="flex h-40 items-end gap-1.5">
                        {sales_by_day.map((d, i) => (
                            <div key={i} className="group flex flex-1 flex-col items-center justify-end gap-1">
                                <div className="w-full rounded-t bg-foreground/80 transition-colors group-hover:bg-foreground" style={{ height: `${Math.round((d.total / peak) * 100)}%`, minHeight: d.total > 0 ? 3 : 0 }} title={`${d.label}: RM ${d.total.toFixed(2)}`} />
                                <span className="text-[9px] text-muted-foreground">{d.label.split(' ')[0]}</span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Calendar */}
                <div className="mt-6">
                    <EventCalendar events={calendar} />
                </div>

                <div className="mt-6 grid gap-6 lg:grid-cols-2">
                    {/* Upcoming */}
                    <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Upcoming events</h2>
                        {upcoming.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No upcoming published events.</p>
                        ) : (
                            <ul className="grid gap-2 text-sm">
                                {upcoming.map((e) => (
                                    <li key={e.slug} className="flex items-center justify-between border-b border-border/60 py-2 last:border-0">
                                        <div>
                                            <Link href={`/host/events/${e.slug}/edit`} className="font-medium hover:underline">{e.title}</Link>
                                            <div className="text-xs text-muted-foreground">{e.when}</div>
                                        </div>
                                        <span className="text-xs text-muted-foreground whitespace-nowrap"><Ticket className="mr-1 inline size-3" />{e.sold}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {/* Recent orders */}
                    <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Recent orders</h2>
                        {recent_orders.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No paid orders yet.</p>
                        ) : (
                            <ul className="grid gap-2 text-sm">
                                {recent_orders.map((o) => (
                                    <li key={o.reference} className="flex items-center justify-between border-b border-border/60 py-2 last:border-0">
                                        <div>
                                            <div className="font-medium">{o.buyer ?? 'Guest'}</div>
                                            <div className="text-xs text-muted-foreground">{o.event} · {o.at}</div>
                                        </div>
                                        <span className="font-semibold whitespace-nowrap">RM {o.total.toFixed(2)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
