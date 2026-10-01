import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Download, Mail, MessageCircle, Search, UserRound } from 'lucide-react';
import { useState } from 'react';
import { AnalyticsToolbar } from '@/components/analytics-toolbar';
import { whatsappUrl } from '@/components/attendee-profile';
import { PALETTE, SeriesBars } from '@/components/charts';
import { CheckoutFunnel } from '@/components/checkout-funnel';
import type { FunnelSummary } from '@/components/checkout-funnel';
import { ResponsiveTable } from '@/components/responsive-table';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';

interface Row {
    key: number;
    event: string;
    event_slug: string | null;
    event_date: string | null;
    stage: 'started' | 'details';
    name: string | null;
    email: string | null;
    phone: string | null;
    city: string | null;
    account: boolean;
    consent: 'checkout' | 'account' | null;
    /** Opted in to marketing email (email_consents), not the RSVP terms. */
    marketing: boolean;
    tickets: number;
    items: string;
    value: number;
    currency: string;
    attempts: number;
    reference: string;
    last_at_label: string | null;
    recovered: boolean;
}
interface ByEvent { event: string; slug: string | null; people: number; details: number; recovered: number; lost_value: number }
interface Paginated { data: Row[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number; total: number }
interface Filters { period: string; from: string; to: string; periodLabel: string; event: string; stage: string; q: string; recovered: string }
interface Props {
    summary: FunnelSummary;
    trend: { date: string; paid: number; abandoned: number }[];
    byEvent: ByEvent[];
    rows: Paginated;
    event: { slug: string; title: string } | null;
    filters: Filters;
    stageOptions: { value: string; label: string }[];
    exportUrl: string;
}

const PATH = '/admin/analytics/abandoned';
const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export default function AbandonedCheckouts({ summary, trend, byEvent, rows, event, filters, stageOptions, exportUrl }: Props) {
    const [q, setQ] = useState(filters.q);

    const windowParams: Record<string, string> = filters.period === 'custom'
        ? { period: 'custom', from: filters.from, to: filters.to }
        : { period: filters.period };
    const extra: Record<string, string> = {
        ...(filters.event ? { event: filters.event } : {}),
        ...(filters.stage ? { stage: filters.stage } : {}),
        ...(filters.q ? { q: filters.q } : {}),
        ...(filters.recovered ? { recovered: '1' } : {}),
    };

    const nav = (patch: Record<string, string | undefined>) => {
        const clean: Record<string, string> = {};

        for (const [k, v] of Object.entries({ ...windowParams, ...extra, ...patch })) {
            if (v) {
                clean[k] = v;
            }
        }

        router.get(PATH, clean, { preserveScroll: true, preserveState: true });
    };

    return (
        <>
            <Head title="Abandoned checkouts" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <Link href={event ? `/admin/analytics/${event.slug}` : '/admin/analytics'} className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" /> {event ? `Back to ${event.title}` : 'Back to analytics'}
                </Link>

                <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="mb-1 text-2xl font-bold tracking-tight">Abandoned checkouts{event && <span className="text-muted-foreground"> · {event.title}</span>}</h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            Everyone who picked tickets — or went further and filled in their details — but never paid. One row per person per event; people who came back and paid are left out unless you include them.
                        </p>
                    </div>
                    <AnalyticsToolbar path={PATH} filters={filters} extra={extra} />
                </div>

                <CheckoutFunnel data={summary} title={`Checkout funnel · ${filters.periodLabel}`} />

                <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-4 text-sm font-semibold">Paid vs abandoned · {filters.periodLabel}</h2>
                        <SeriesBars data={trend} series={[{ key: 'paid', name: 'Paid', color: PALETTE[6] }, { key: 'abandoned', name: 'Abandoned', color: PALETTE[3] }]} />
                    </section>

                    {!event && (
                        <section className="rounded-2xl border border-border bg-card shadow-sm">
                            <h2 className="border-b border-border p-4 text-sm font-semibold">Events losing the most buyers</h2>
                            <ResponsiveTable>
                                <table className="w-full text-sm">
                                    <thead className="text-xs text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2 text-left font-medium">Event</th>
                                            <th className="px-3 py-2 text-right font-medium">People</th>
                                            <th className="px-3 py-2 text-right font-medium">Filled details</th>
                                            <th className="px-3 py-2 text-right font-medium">Came back</th>
                                            <th className="px-3 py-2 text-right font-medium">Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {byEvent.length === 0 && <tr><td colSpan={5} className="px-3 py-8 text-center text-muted-foreground">No abandoned checkouts in this period.</td></tr>}
                                        {byEvent.map((e) => (
                                            <tr key={e.slug ?? e.event} className="border-t border-border/60 hover:bg-muted/40">
                                                <td className="max-w-[200px] truncate px-3 py-2">
                                                    {e.slug ? <button type="button" onClick={() => nav({ event: e.slug ?? undefined, page: undefined })} className="font-medium hover:underline">{e.event}</button> : e.event}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">{e.people}</td>
                                                <td className="px-3 py-2 text-right tabular-nums">{e.details}</td>
                                                <td className="px-3 py-2 text-right tabular-nums">{e.recovered}</td>
                                                <td className="px-3 py-2 text-right tabular-nums">{rm(e.lost_value)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </ResponsiveTable>
                        </section>
                    )}
                </div>

                {/* The contact list */}
                <section className="mt-8 rounded-2xl border border-border bg-card shadow-sm">
                    <div className="flex flex-col gap-3 border-b border-border p-4">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h2 className="flex items-center gap-2 text-sm font-semibold"><UserRound className="size-4" /> People to follow up <span className="text-muted-foreground">({rows.total.toLocaleString()})</span></h2>
                            <div className="flex items-center gap-2">
                                <form onSubmit={(e) => {
                                    e.preventDefault();
                                    nav({ q: q || undefined, page: undefined });
                                }} className="relative">
                                    <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, email, phone, event…" className="h-9 w-48 rounded-lg border border-input bg-card pl-8 pr-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20 sm:w-64" />
                                </form>
                                <Button asChild variant="outline" size="sm"><a href={exportUrl}><Download className="size-4" /> Export CSV</a></Button>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="text-xs font-medium text-muted-foreground">Filter:</span>
                            <div className="w-64"><AppSelect value={filters.stage || 'all'} onChange={(v) => nav({ stage: v === 'all' ? undefined : v, page: undefined })} options={[{ value: 'all', label: 'Every stage' }, ...stageOptions]} /></div>
                            {event && (
                                <button type="button" onClick={() => nav({ event: undefined, page: undefined })} className="rounded-full border border-border bg-muted/40 px-2.5 py-1 text-xs font-medium hover:bg-accent">
                                    {event.title} ✕
                                </button>
                            )}
                            <span className="flex items-center gap-2 text-xs">
                                <Switch checked={!!filters.recovered} onCheckedChange={(v) => nav({ recovered: v ? '1' : undefined, page: undefined })} aria-label="Include people who came back and paid" />
                                Include people who came back &amp; paid
                            </span>
                        </div>
                    </div>

                    <ResponsiveTable>
                        <table className="w-full min-w-[900px] text-sm">
                            <thead className="border-b border-border text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 text-left font-medium">Person</th>
                                    <th className="px-3 py-2 text-left font-medium">Event</th>
                                    <th className="px-3 py-2 text-left font-medium">Stage</th>
                                    <th className="px-3 py-2 text-left font-medium">Basket</th>
                                    <th className="px-3 py-2 text-right font-medium">Tries</th>
                                    <th className="px-3 py-2 text-left font-medium">Last attempt</th>
                                    <th className="px-3 py-2 text-left font-medium">Contact</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.data.length === 0 && (
                                    <tr><td colSpan={7} className="px-3 py-10 text-center text-muted-foreground">Nobody here — no abandoned checkouts match.</td></tr>
                                )}
                                {rows.data.map((r) => {
                                    const wa = r.phone ? whatsappUrl(r.phone) : null;

                                    return (
                                        <tr key={r.key} className="border-b border-border/60 align-top last:border-0 hover:bg-muted/40">
                                            <td className="px-3 py-2">
                                                <div className="font-medium">{r.name || (r.email ? '—' : 'Anonymous guest')}</div>
                                                {r.email && <div className="text-xs text-muted-foreground">{r.email}</div>}
                                                {r.phone && <div className="text-xs text-muted-foreground">{r.phone}</div>}
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {r.account && <Badge>Member</Badge>}
                                                    {r.recovered && <Badge tone="green">Came back &amp; paid</Badge>}
                                                    {r.city && <Badge>{r.city}</Badge>}
                                                </div>
                                            </td>
                                            <td className="max-w-[200px] px-3 py-2">
                                                <div className="truncate">{r.event}</div>
                                                {r.event_date && <div className="text-xs text-muted-foreground">{r.event_date}</div>}
                                            </td>
                                            <td className="px-3 py-2">
                                                <Badge tone={r.stage === 'details' ? 'amber' : undefined}>{r.stage === 'details' ? 'Filled details' : 'Left at checkout'}</Badge>
                                            </td>
                                            <td className="max-w-[220px] px-3 py-2">
                                                <div className="font-medium tabular-nums">{rm(r.value)}</div>
                                                <div className="truncate text-xs text-muted-foreground" title={r.items}>{r.items}</div>
                                            </td>
                                            <td className="px-3 py-2 text-right tabular-nums">{r.attempts}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-xs text-muted-foreground">
                                                {r.last_at_label}
                                                <div className="font-mono">{r.reference}</div>
                                            </td>
                                            <td className="px-3 py-2">
                                                <div className="flex gap-1.5">
                                                    {r.email && <a href={`mailto:${r.email}`} title="Email" className="rounded-md border border-border p-1.5 hover:bg-accent"><Mail className="size-3.5" /></a>}
                                                    {wa && <a href={wa} target="_blank" rel="noreferrer" title="WhatsApp" className="rounded-md border border-border p-1.5 hover:bg-accent"><MessageCircle className="size-3.5" /></a>}
                                                </div>
                                                {r.consent && <div className="mt-1 text-[10px] text-muted-foreground">{r.consent === 'checkout' ? 'Agreed to RSVP terms' : 'Registered member'}</div>}
                                                {r.marketing && <div className="mt-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">Opted in to marketing</div>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </ResponsiveTable>

                    {(rows.prev_page_url || rows.next_page_url) && (
                        <div className="flex items-center justify-between gap-2 border-t border-border p-3 text-sm">
                            <span className="text-muted-foreground">Page {rows.current_page} of {rows.last_page}</span>
                            <div className="flex gap-2">
                                <Button asChild variant="outline" size="sm" disabled={!rows.prev_page_url}>{rows.prev_page_url ? <Link href={rows.prev_page_url} preserveScroll>← Prev</Link> : <span>← Prev</span>}</Button>
                                <Button asChild variant="outline" size="sm" disabled={!rows.next_page_url}>{rows.next_page_url ? <Link href={rows.next_page_url} preserveScroll>Next →</Link> : <span>Next →</span>}</Button>
                            </div>
                        </div>
                    )}
                </section>

                <p className="mt-3 text-xs text-muted-foreground">
                    A checkout counts as abandoned once its 30-minute ticket hold runs out unpaid. Guests who left before typing anything can be counted but not contacted. Agreeing to the RSVP terms is required to buy, so it is not permission to market: only people marked “Opted in to marketing” may receive promotional email.
                </p>
            </div>
        </>
    );
}

function Badge({ children, tone }: { children: React.ReactNode; tone?: 'green' | 'amber' }) {
    const cls = tone === 'green'
        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
        : tone === 'amber'
            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
            : 'bg-muted text-muted-foreground';

    return <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium ${cls}`}>{children}</span>;
}

AbandonedCheckouts.layout = {
    breadcrumbs: [{ title: 'Analytics', href: '/admin/analytics' }, { title: 'Abandoned checkouts', href: PATH }],
};
