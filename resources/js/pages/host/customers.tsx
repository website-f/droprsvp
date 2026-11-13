import { Head, Link, router } from '@inertiajs/react';
import { Download, Mail, Phone, Search, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface Customer {
    name: string; email: string; phone: string | null; city: string | null;
    orders: number; tickets: number; spent: number; events: string[];
    first_at: string | null; last_at: string | null; subscribed: boolean;
}
interface PageLink { url: string | null; label: string; active: boolean }
interface Props {
    customers: { data: Customer[]; links: PageLink[]; from: number | null; to: number | null; total: number };
    totals: { people: number; orders: number; tickets: number; spent: number };
    filters: { q: string; event: string; sort: string };
    events: { value: string; label: string }[];
}

const money = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const SORTS = [
    { value: 'recent', label: 'Latest purchase' },
    { value: 'spent', label: 'Spent the most' },
    { value: 'orders', label: 'Most orders' },
    { value: 'name', label: 'Name (A–Z)' },
];

/**
 * Everyone who has bought from you, across all your events — one row per
 * person — with a CSV export of the same list.
 */
export default function Customers({ customers, totals, filters, events }: Props) {
    const [q, setQ] = useState(filters.q);
    const first = useRef(true);

    const go = (patch: Partial<Props['filters']>) => {
        const next = { ...filters, q, ...patch };
        const params = Object.fromEntries(Object.entries(next).filter(([k, v]) => v && !(k === 'sort' && v === 'recent')));
        router.get('/host/customers', params, { preserveScroll: true, preserveState: true, replace: true });
    };

    // Search as they type, without a request per keystroke.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const t = window.setTimeout(() => go({ q }), 350);

        return () => window.clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    const exportUrl = `/host/customers/export?${new URLSearchParams(Object.entries({ q: filters.q, event: filters.event }).filter(([, v]) => v) as [string, string][]).toString()}`;

    return (
        <>
            <Head title="Customers" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <div className="mb-1 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-bold tracking-tight">Customers</h1>
                    <Button asChild variant="outline">
                        <a href={exportUrl}><Download className="size-4" /> Export CSV</a>
                    </Button>
                </div>
                <p className="mb-5 text-sm text-muted-foreground">Everyone who has bought a ticket to your events, one row per person. The export includes the filters you have set.</p>

                <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {[
                        ['Customers', totals.people.toLocaleString()],
                        ['Orders', totals.orders.toLocaleString()],
                        ['Tickets', totals.tickets.toLocaleString()],
                        ['Spent', money(totals.spent)],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-xl border border-border bg-card p-4 shadow-sm">
                            <div className="text-xs uppercase tracking-wide text-muted-foreground">{label}</div>
                            <div className="mt-1 text-xl font-bold tabular-nums">{value}</div>
                        </div>
                    ))}
                </div>

                <div className="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_14rem_12rem]">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            type="search"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search name, email or phone"
                            aria-label="Search customers"
                            className="h-10 w-full rounded-lg border border-input bg-card pr-3 pl-9 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                        />
                    </div>
                    <AppSelect aria-label="Event" value={filters.event || 'all'} onChange={(v) => go({ event: v === 'all' ? '' : v })} options={[{ value: 'all', label: 'All events' }, ...events]} className="h-10" />
                    <AppSelect aria-label="Sort" value={filters.sort} onChange={(v) => go({ sort: v })} options={SORTS} className="h-10" />
                </div>

                {customers.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border py-16 text-center">
                        <Users className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">{filters.q || filters.event ? 'No customers match.' : 'No customers yet — they appear here once someone buys a ticket.'}</p>
                    </div>
                ) : (
                    <>
                        {/* Phones: one card per person. */}
                        <ul className="grid gap-2 md:hidden">
                            {customers.data.map((c) => (
                                <li key={c.email} className="rounded-xl border border-border bg-card p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="font-medium [overflow-wrap:anywhere]">{c.name}</div>
                                            <a href={`mailto:${c.email}`} className="block text-xs text-muted-foreground [overflow-wrap:anywhere]">{c.email}</a>
                                            {c.phone && <a href={`tel:${c.phone}`} className="block text-xs text-muted-foreground">{c.phone}</a>}
                                        </div>
                                        <div className="shrink-0 text-right">
                                            <div className="font-semibold tabular-nums">{money(c.spent)}</div>
                                            <div className="text-xs text-muted-foreground">{c.orders} order{c.orders === 1 ? '' : 's'} · {c.tickets} ticket{c.tickets === 1 ? '' : 's'}</div>
                                        </div>
                                    </div>
                                    <div className="mt-2 text-xs text-muted-foreground [overflow-wrap:anywhere]">{c.events.join(' · ')}</div>
                                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <span>Last bought {c.last_at}</span>
                                        {c.subscribed && <span className="rounded-full bg-muted px-2 py-0.5">Gets your emails</span>}
                                    </div>
                                </li>
                            ))}
                        </ul>

                        {/* Wider screens: a table. */}
                        <div className="hidden overflow-x-auto rounded-xl border border-border bg-card md:block">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-border text-left text-xs text-muted-foreground">
                                        <th className="px-4 py-3 font-medium">Customer</th>
                                        <th className="px-4 py-3 font-medium">Events</th>
                                        <th className="px-4 py-3 text-right font-medium">Orders</th>
                                        <th className="px-4 py-3 text-right font-medium">Tickets</th>
                                        <th className="px-4 py-3 text-right font-medium">Spent</th>
                                        <th className="px-4 py-3 font-medium">Last bought</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {customers.data.map((c) => (
                                        <tr key={c.email} className="border-b border-border/60 align-top last:border-0">
                                            <td className="px-4 py-3">
                                                <div className="font-medium">{c.name}</div>
                                                <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                                    <a href={`mailto:${c.email}`} className="inline-flex items-center gap-1 hover:text-foreground"><Mail className="size-3" />{c.email}</a>
                                                    {c.phone && <a href={`tel:${c.phone}`} className="inline-flex items-center gap-1 hover:text-foreground"><Phone className="size-3" />{c.phone}</a>}
                                                </div>
                                                <div className="mt-1 flex flex-wrap gap-1.5 text-[11px] text-muted-foreground">
                                                    {c.city && <span>{c.city}</span>}
                                                    {c.subscribed && <span className="rounded-full bg-muted px-2 py-0.5">Gets your emails</span>}
                                                </div>
                                            </td>
                                            <td className="max-w-xs px-4 py-3 text-xs text-muted-foreground">{c.events.join(' · ')}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{c.orders}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{c.tickets}</td>
                                            <td className="px-4 py-3 text-right font-medium tabular-nums">{money(c.spent)}</td>
                                            <td className="px-4 py-3 text-xs whitespace-nowrap text-muted-foreground">{c.last_at}{c.first_at && c.first_at !== c.last_at ? <div>since {c.first_at}</div> : null}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                            <span className="text-muted-foreground">{customers.from}–{customers.to} of {customers.total.toLocaleString()}</span>
                            <div className="flex flex-wrap gap-1">
                                {customers.links.map((l, i) => (
                                    l.url ? (
                                        <Link key={i} href={l.url} preserveScroll className={cn('rounded-md border px-3 py-1.5 text-xs', l.active ? 'border-foreground bg-foreground text-background' : 'border-border hover:bg-accent')} dangerouslySetInnerHTML={{ __html: l.label }} />
                                    ) : (
                                        <span key={i} className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground opacity-50" dangerouslySetInnerHTML={{ __html: l.label }} />
                                    )
                                ))}
                            </div>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

Customers.layout = {
    breadcrumbs: [{ title: 'Customers', href: '/host/customers' }],
};
