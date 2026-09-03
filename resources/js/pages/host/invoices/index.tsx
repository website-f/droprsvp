import { Head, Link } from '@inertiajs/react';
import { Banknote, CalendarDays, ChevronRight, Download, FileText } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Payout { reference: string; amount: number; currency: string; status: string; requested_at: string | null; paid_at: string | null }
interface EventRow { slug: string; title: string; status: string; when: string | null; invoices: number; revenue: number }
interface Paginated { data: EventRow[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number }
interface Props { payouts: Payout[]; events: Paginated }

const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
type Tab = 'events' | 'payouts';

export default function HostInvoices({ payouts, events }: Props) {
    const [tab, setTab] = useState<Tab>('events');

    return (
        <>
            <Head title="Invoices" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight">Invoices</h1>
                    <p className="text-sm text-muted-foreground">Attendee invoices for each of your events, and your payout invoices.</p>
                </div>

                <div className="mb-6 flex gap-1 border-b border-border">
                    {([['events', 'Event invoices', CalendarDays], ['payouts', 'Payout invoices', Banknote]] as const).map(([key, label, Icon]) => (
                        <button key={key} type="button" onClick={() => setTab(key)}
                            className={`-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors ${tab === key ? 'border-foreground text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'}`}>
                            <Icon className="size-4" /> {label}
                        </button>
                    ))}
                </div>

                {tab === 'events' && (
                    events.data.length === 0 ? (
                        <p className="rounded-2xl border border-dashed border-border py-16 text-center text-sm text-muted-foreground">No events yet.</p>
                    ) : (
                        <>
                            <div className="overflow-hidden rounded-2xl border border-border">
                                <table className="w-full min-w-[560px] text-sm">
                                    <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">Event</th>
                                            <th className="px-4 py-3 text-right font-medium">Invoices</th>
                                            <th className="px-4 py-3 text-right font-medium">Revenue</th>
                                            <th className="px-4 py-3" />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {events.data.map((e) => (
                                            <tr key={e.slug} className="hover:bg-muted/30">
                                                <td className="px-4 py-3">
                                                    <div className="font-medium">{e.title}</div>
                                                    <div className="text-xs text-muted-foreground">{e.when ?? 'No date'} · <span className="capitalize">{e.status}</span></div>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">{e.invoices}</td>
                                                <td className="px-4 py-3 text-right font-medium tabular-nums">{rm(e.revenue)}</td>
                                                <td className="px-4 py-3 text-right">
                                                    <Button asChild size="sm" variant="outline">
                                                        <Link href={`/host/invoices/events/${e.slug}`}>Open invoices <ChevronRight className="size-3.5" /></Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {(events.prev_page_url || events.next_page_url) && (
                                <div className="mt-6 flex items-center justify-between gap-2 text-sm">
                                    <span className="text-muted-foreground">Page {events.current_page} of {events.last_page}</span>
                                    <div className="flex gap-2">
                                        <Button asChild variant="outline" size="sm" disabled={!events.prev_page_url}>{events.prev_page_url ? <Link href={events.prev_page_url} preserveScroll>← Prev</Link> : <span>← Prev</span>}</Button>
                                        <Button asChild variant="outline" size="sm" disabled={!events.next_page_url}>{events.next_page_url ? <Link href={events.next_page_url} preserveScroll>Next →</Link> : <span>Next →</span>}</Button>
                                    </div>
                                </div>
                            )}
                        </>
                    )
                )}

                {tab === 'payouts' && (
                    payouts.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border py-16 text-center">
                            <span className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"><FileText className="size-5" /></span>
                            <p className="mt-3 text-sm font-medium">No payout invoices yet</p>
                            <p className="mt-1 text-xs text-muted-foreground">Once you request and receive a payout, its invoice shows up here.</p>
                        </div>
                    ) : (
                        <ul className="grid gap-2">
                            {payouts.map((p) => (
                                <li key={p.reference} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="font-semibold tabular-nums">{rm(p.amount)}</span>
                                            <Badge variant={p.status === 'paid' ? 'default' : 'secondary'} className="capitalize">{p.status}</Badge>
                                        </div>
                                        <div className="mt-0.5 text-xs text-muted-foreground">{p.reference} · requested {p.requested_at}{p.paid_at ? ` · paid ${p.paid_at}` : ''}</div>
                                    </div>
                                    {p.status === 'paid' && (
                                        <a href={`/my/payouts/${p.reference}/receipt`} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground hover:text-foreground"><Download className="size-4" /> Invoice</a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )
                )}
            </div>
        </>
    );
}

HostInvoices.layout = {
    breadcrumbs: [{ title: 'Invoices', href: '/host/invoices' }],
};
