import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, ChevronLeft, ChevronRight, Download, RotateCcw, ScanLine, Search, Ticket as TicketIcon, Users } from 'lucide-react';
import { useRef, useState } from 'react';
import { QrScanner  } from '@/components/qr-scanner';
import type {ScanFeedback} from '@/components/qr-scanner';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { postJson } from '@/lib/api';

interface Row {
    id: number; token: string; name: string; email: string | null; phone: string | null;
    type: string | null; seat: string | null; order_ref: string | null; purchased_at: string | null;
    status: string; checked_in_at: string | null;
}
interface Paginated<T> {
    data: T[]; current_page: number; last_page: number; total: number;
    from: number | null; to: number | null; prev_page_url: string | null; next_page_url: string | null;
}
interface Filters { q: string; status: string; type: string }
interface Stats { total: number; checked_in: number }
interface Props {
    event: { title: string; slug: string };
    tickets: Paginated<Row>;
    filters: Filters;
    ticketTypes: { id: number; name: string }[];
    stats: Stats;
    openScanner?: boolean;
}
interface ScanResponse { result: 'ok' | 'already' | 'valid' | 'invalid' | 'notfound'; message: string; ticket?: Row; stats?: Stats }

const STATUS_BADGE: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    checked_in: 'default', valid: 'secondary', void: 'destructive', refunded: 'destructive',
};
const STATUS_LABEL: Record<string, string> = {
    checked_in: 'Checked in', valid: 'Not checked in', void: 'Void', refunded: 'Refunded',
};

export default function Attendees({ event, tickets, filters, ticketTypes, stats, openScanner = false }: Props) {
    // Rows + stats live locally so scans/check-ins update instantly without a reload;
    // they re-sync whenever Inertia replaces the props (filter change / pagination).
    const [rows, setRows] = useState<Row[]>(tickets.data);
    const [liveStats, setLiveStats] = useState<Stats>(stats);
    const [syncedFrom, setSyncedFrom] = useState(tickets);

    if (syncedFrom !== tickets) {
        setSyncedFrom(tickets);
        setRows(tickets.data);
        setLiveStats(stats);
    }

    const [q, setQ] = useState(filters.q);
    const searchTimer = useRef<number | undefined>(undefined);
    const [detail, setDetail] = useState<Row | null>(null);

    // Scanner state — opens immediately when reached via the "Check-in (scan)" shortcut.
    const [scanOpen, setScanOpen] = useState(openScanner);
    const [auto, setAuto] = useState(true);
    const [feedback, setFeedback] = useState<ScanFeedback | null>(null);
    const [pending, setPending] = useState<Row | null>(null); // valid ticket awaiting manual confirm
    const [working, setWorking] = useState(false);

    const base = `/host/events/${event.slug}/attendees`;

    const applyFilters = (patch: Partial<Filters>) => {
        const next = { ...filters, q, ...patch };
        const params: Record<string, string> = {};

        if (next.q) {
params.q = next.q;
}

        if (next.status && next.status !== 'all') {
params.status = next.status;
}

        if (next.type && next.type !== 'all') {
params.type = next.type;
}

        router.get(base, params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const onSearch = (v: string) => {
        setQ(v);
        window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(() => applyFilters({ q: v }), 350);
    };

    const exportUrl = () => {
        const p = new URLSearchParams();

        if (q) {
p.set('q', q);
}

        if (filters.status && filters.status !== 'all') {
p.set('status', filters.status);
}

        if (filters.type && filters.type !== 'all') {
p.set('type', filters.type);
}

        const qs = p.toString();

        return `${base}/export${qs ? `?${qs}` : ''}`;
    };

    const mergeTicket = (t?: Row, s?: Stats) => {
        if (t) {
            setRows((prev) => prev.map((r) => (r.id === t.id ? t : r)));
            setDetail((d) => (d && d.id === t.id ? t : d));
        }

        if (s) {
            setLiveStats(s);
        }
    };

    // A code was read from the camera (or typed manually).
    const onDecode = async (raw: string) => {
        if (working) {
            return;
        }

        setWorking(true);

        try {
            const res = await postJson<ScanResponse>(`${base}/scan`, { token: raw, auto });
            mergeTicket(res.ticket, res.stats);
            const name = res.ticket?.name;

            if (res.result === 'ok') {
                setFeedback({ tone: 'ok', title: 'Checked in', subtitle: [name, res.ticket?.type].filter(Boolean).join(' · ') });
                setPending(null);
            } else if (res.result === 'already') {
                setFeedback({ tone: 'warn', title: name ? `${name} — already in` : 'Already checked in', subtitle: res.message });
                setPending(null);
            } else if (res.result === 'valid') {
                setFeedback({ tone: 'info', title: name ?? 'Valid ticket', subtitle: [res.ticket?.type, res.ticket?.seat].filter(Boolean).join(' · ') || res.message });
                setPending(res.ticket ?? null);
            } else {
                setFeedback({ tone: 'error', title: res.result === 'notfound' ? 'Not found' : 'Invalid', subtitle: res.message });
                setPending(null);
            }
        } catch {
            setFeedback({ tone: 'error', title: 'Scan failed', subtitle: 'Please try again.' });
        } finally {
            setWorking(false);
        }
    };

    const checkIn = async (id: number, fromScanner = false) => {
        setWorking(true);

        try {
            const res = await postJson<{ ticket: Row; stats: Stats }>(`${base}/${id}/check-in`);
            mergeTicket(res.ticket, res.stats);

            if (fromScanner) {
                setFeedback({ tone: 'ok', title: 'Checked in', subtitle: [res.ticket.name, res.ticket.type].filter(Boolean).join(' · ') });
                setPending(null);
            }
        } finally {
            setWorking(false);
        }
    };

    const undo = async (id: number) => {
        setWorking(true);

        try {
            const res = await postJson<{ ticket: Row; stats: Stats }>(`${base}/${id}/undo`);
            mergeTicket(res.ticket, res.stats);
        } finally {
            setWorking(false);
        }
    };

    const pct = liveStats.total ? Math.round((liveStats.checked_in / liveStats.total) * 100) : 0;

    return (
        <>
            <Head title={`Attendees · ${event.title}`} />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/host/events"><ArrowLeft className="size-4" /></Link></Button>
                    <div className="flex-1">
                        <h1 className="text-xl font-bold tracking-tight">Attendees</h1>
                        <p className="text-sm text-muted-foreground">{event.title}</p>
                    </div>
                    <Button onClick={() => {
 setFeedback(null); setPending(null); setScanOpen(true); 
}}><ScanLine className="size-4" /> Scan QR</Button>
                </div>

                {/* Stats */}
                <div className="mb-5 grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border border-border bg-card p-4">
                        <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-muted-foreground"><Users className="size-3.5" /> Admissions</div>
                        <div className="mt-1 text-2xl font-bold">{liveStats.total}</div>
                    </div>
                    <div className="rounded-xl border border-border bg-card p-4">
                        <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-muted-foreground"><CheckCircle2 className="size-3.5" /> Checked in</div>
                        <div className="mt-1 text-2xl font-bold">{liveStats.checked_in} <span className="text-sm font-medium text-muted-foreground">/ {liveStats.total}</span></div>
                    </div>
                    <div className="rounded-xl border border-border bg-card p-4">
                        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Progress</div>
                        <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-foreground transition-all" style={{ width: `${pct}%` }} /></div>
                        <div className="mt-1 text-xs text-muted-foreground">{pct}% arrived</div>
                    </div>
                </div>

                {/* Toolbar */}
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <div className="flex h-10 min-w-56 flex-1 items-center gap-2 rounded-lg border border-input bg-card px-3">
                        <Search className="size-4 text-muted-foreground" />
                        <input value={q} onChange={(e) => onSearch(e.target.value)} placeholder="Search name, email, order, code…" className="w-full bg-transparent text-sm outline-none" />
                    </div>
                    <div className="w-40"><AppSelect value={filters.status || 'all'} onChange={(v) => applyFilters({ status: v })} options={[{ value: 'all', label: 'All statuses' }, { value: 'valid', label: 'Not checked in' }, { value: 'checked_in', label: 'Checked in' }, { value: 'refunded', label: 'Refunded' }, { value: 'void', label: 'Void' }]} /></div>
                    {ticketTypes.length > 0 && (
                        <div className="w-44"><AppSelect value={filters.type || 'all'} onChange={(v) => applyFilters({ type: v })} options={[{ value: 'all', label: 'All ticket types' }, ...ticketTypes.map((t) => ({ value: String(t.id), label: t.name }))]} /></div>
                    )}
                    <Button asChild variant="outline" size="sm"><a href={exportUrl()}><Download className="size-4" /> Export</a></Button>
                </div>

                {/* Table */}
                {rows.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border py-16 text-center">
                        <TicketIcon className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">No admissions match your filters yet.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Attendee</th>
                                    <th className="px-4 py-3 font-medium">Ticket</th>
                                    <th className="px-4 py-3 font-medium">Order</th>
                                    <th className="px-4 py-3 font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {rows.map((r) => (
                                    <tr key={r.id} className="cursor-pointer hover:bg-muted/30" onClick={() => setDetail(r)}>
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{r.name}</div>
                                            <div className="text-xs text-muted-foreground">{r.email ?? '—'}</div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div>{r.type ?? '—'}</div>
                                            {r.seat && <div className="text-xs text-muted-foreground">{r.seat}</div>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="text-xs text-muted-foreground">{r.order_ref ?? '—'}</div>
                                            <div className="text-xs text-muted-foreground">{r.purchased_at ?? ''}</div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant={STATUS_BADGE[r.status] ?? 'secondary'}>{STATUS_LABEL[r.status] ?? r.status}</Badge>
                                            {r.checked_in_at && <div className="mt-0.5 text-[11px] text-muted-foreground">{r.checked_in_at}</div>}
                                        </td>
                                        <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                            {r.status === 'valid' && <Button size="sm" variant="outline" disabled={working} onClick={() => checkIn(r.id)}>Check in</Button>}
                                            {r.status === 'checked_in' && <Button size="sm" variant="ghost" disabled={working} onClick={() => undo(r.id)}><RotateCcw className="size-3.5" /> Undo</Button>}
                                            {(r.status === 'void' || r.status === 'refunded') && <span className="text-xs text-muted-foreground">—</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Pagination */}
                {tickets.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                        <span>{tickets.from}–{tickets.to} of {tickets.total}</span>
                        <div className="flex gap-2">
                            <Button asChild={!!tickets.prev_page_url} variant="outline" size="sm" disabled={!tickets.prev_page_url}>
                                {tickets.prev_page_url ? <Link href={tickets.prev_page_url} preserveScroll><ChevronLeft className="size-4" /> Prev</Link> : <span><ChevronLeft className="size-4" /> Prev</span>}
                            </Button>
                            <Button asChild={!!tickets.next_page_url} variant="outline" size="sm" disabled={!tickets.next_page_url}>
                                {tickets.next_page_url ? <Link href={tickets.next_page_url} preserveScroll>Next <ChevronRight className="size-4" /></Link> : <span>Next <ChevronRight className="size-4" /></span>}
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            {/* Detail drawer */}
            <Dialog open={!!detail} onOpenChange={(o) => !o && setDetail(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader><DialogTitle>{detail?.name}</DialogTitle></DialogHeader>
                    {detail && (
                        <div className="grid gap-3 text-sm">
                            <div className="flex items-center justify-between"><span className="text-muted-foreground">Status</span><Badge variant={STATUS_BADGE[detail.status] ?? 'secondary'}>{STATUS_LABEL[detail.status] ?? detail.status}</Badge></div>
                            {detail.checked_in_at && <Field label="Checked in at" value={detail.checked_in_at} />}
                            <Field label="Email" value={detail.email} />
                            <Field label="Phone" value={detail.phone} />
                            <Field label="Ticket type" value={detail.type} />
                            <Field label="Seat / Table" value={detail.seat} />
                            <Field label="Order" value={detail.order_ref} />
                            <Field label="Purchased" value={detail.purchased_at} />
                            <Field label="Code" value={detail.token} mono />
                            <div className="mt-1 flex gap-2">
                                {detail.status === 'valid' && <Button className="flex-1" disabled={working} onClick={() => checkIn(detail.id)}><CheckCircle2 className="size-4" /> Check in</Button>}
                                {detail.status === 'checked_in' && <Button variant="outline" className="flex-1" disabled={working} onClick={() => undo(detail.id)}><RotateCcw className="size-4" /> Undo check-in</Button>}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/* Fullscreen scanner */}
            <QrScanner
                open={scanOpen}
                onClose={() => {
 setScanOpen(false); setFeedback(null); setPending(null); 
}}
                onDecode={onDecode}
                paused={working || !!pending}
                auto={auto}
                onAutoChange={setAuto}
                feedback={feedback}
                actions={pending && (
                    <>
                        <button type="button" disabled={working} onClick={() => checkIn(pending.id, true)} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">Check in {pending.name}</button>
                        <button type="button" onClick={() => {
 setPending(null); setFeedback(null); 
}} className="rounded-lg bg-white/15 px-4 py-2 text-sm font-semibold text-white">Scan next</button>
                    </>
                )}
            />
        </>
    );
}

function Field({ label, value, mono }: { label: string; value: string | null | undefined; mono?: boolean }) {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className={`text-right ${mono ? 'font-mono text-xs' : ''}`}>{value || '—'}</span>
        </div>
    );
}

Attendees.layout = {
    breadcrumbs: [
        { title: 'Events', href: '/host/events' },
        { title: 'Attendees', href: '#' },
    ],
};
