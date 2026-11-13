import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Check, Download, Info, ListOrdered, ScanLine, Search, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface Day { date: string; label: string; present: number }
interface Row { id: number; name: string; email: string | null; phone: string | null; type: string | null; order: string | null; days: Record<string, string>; attended: number }
interface PageLink { url: string | null; label: string; active: boolean }
interface Filters { q: string; day: string; presence: 'present' | 'absent'; type: string }
interface Props {
    event: { title: string; slug: string; daily: boolean; today: string };
    admissions: number;
    days: Day[];
    rows: { data: Row[]; links: PageLink[]; from: number | null; to: number | null; total: number };
    filters: Filters;
    ticketTypes: { value: string; label: string }[];
}

/**
 * Attendance by day: who was checked in on which day, for events that check
 * people in daily. Each day shows how many came; tap one to list who was
 * there (or who wasn't). Exports the grid or the raw scan log.
 */
export default function Attendance({ event, admissions, days, rows, filters, ticketTypes }: Props) {
    const base = `/host/events/${event.slug}`;
    const [q, setQ] = useState(filters.q);
    const first = useRef(true);

    const go = (patch: Partial<Filters>) => {
        const next = { ...filters, q, ...patch };
        const params = Object.fromEntries(Object.entries(next).filter(([k, v]) => v && !(k === 'presence' && (v === 'present' || !next.day))));
        router.get(`${base}/attendance`, params, { preserveScroll: true, preserveState: true, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const t = window.setTimeout(() => go({ q }), 350);

        return () => window.clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    const exportParams = new URLSearchParams(Object.entries({ q: filters.q, day: filters.day, presence: filters.day ? filters.presence : '', type: filters.type }).filter(([, v]) => v) as [string, string][]).toString();
    const picked = days.find((d) => d.date === filters.day);

    return (
        <>
            <Head title={`Attendance · ${event.title}`} />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <Link href="/host/events" className="mb-3 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Events</Link>
                <div className="mb-1 flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-bold tracking-tight">Attendance by day</h1>
                        <p className="text-sm text-muted-foreground [overflow-wrap:anywhere]">{event.title} · {admissions.toLocaleString()} admission{admissions === 1 ? '' : 's'}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild><Link href={`${base}/attendees?scan=1`}><ScanLine className="size-4" /> Open scanner</Link></Button>
                        <Button asChild variant="outline"><a href={`${base}/attendance/export${exportParams ? `?${exportParams}` : ''}`}><Download className="size-4" /> Export attendance</a></Button>
                        <Button asChild variant="outline"><a href={`${base}/attendance/export?format=log`}><ListOrdered className="size-4" /> Scan log</a></Button>
                    </div>
                </div>

                {!event.daily && (
                    <div className="mt-4 flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm">
                        <Info className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        <span>
                            Each ticket checks in <strong>once</strong> for this event. To scan people in every day, turn on <strong>“Check in every day”</strong> in the{' '}
                            <Link href={`${base}/edit`} className="font-medium underline underline-offset-2">event settings</Link>.
                        </span>
                    </div>
                )}

                {/* One card per day: how many came. Tap to list who was there. */}
                <div className="mt-5 grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(9.5rem,1fr))]">
                    {days.length === 0 && <p className="text-sm text-muted-foreground">No days yet — set the event’s dates, or scan someone in.</p>}
                    {days.map((d) => {
                        const pct = admissions ? Math.round((d.present / admissions) * 100) : 0;
                        const active = filters.day === d.date;

                        return (
                            <button
                                key={d.date}
                                type="button"
                                onClick={() => go({ day: active ? '' : d.date })}
                                className={cn('rounded-xl border p-3 text-left transition-colors', active ? 'border-foreground ring-1 ring-foreground' : 'border-border bg-card hover:border-foreground/40')}
                            >
                                <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                    <span>{d.label}</span>
                                    {d.date === event.today && <span className="rounded-full bg-foreground px-1.5 py-0.5 text-[10px] font-medium text-background">Today</span>}
                                </div>
                                <div className="mt-1 text-xl font-bold tabular-nums">{d.present.toLocaleString()}</div>
                                <div className="text-xs text-muted-foreground">came · {pct}%</div>
                                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-[#2ec4b6]" style={{ width: `${pct}%` }} /></div>
                            </button>
                        );
                    })}
                </div>

                <div className="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_12rem_auto]">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            type="search" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search attendees"
                            placeholder="Search name, email, phone or order"
                            className="h-10 w-full rounded-lg border border-input bg-card pr-3 pl-9 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                        />
                    </div>
                    <AppSelect aria-label="Ticket type" value={filters.type || 'all'} onChange={(v) => go({ type: v === 'all' ? '' : v })} options={[{ value: 'all', label: 'All ticket types' }, ...ticketTypes]} className="h-10" />
                    {picked && (
                        <div className="inline-flex h-10 rounded-lg border border-border p-0.5">
                            {(['present', 'absent'] as const).map((p) => (
                                <button key={p} type="button" onClick={() => go({ presence: p })}
                                    className={cn('rounded-md px-3 text-xs font-medium whitespace-nowrap transition-colors', filters.presence === p ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground')}>
                                    {p === 'present' ? 'Came' : 'Didn’t come'} on {picked.label}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {rows.data.length === 0 ? (
                    <div className="mt-4 flex flex-col items-center gap-3 rounded-xl border border-dashed border-border py-16 text-center">
                        <Users className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">{filters.q || filters.day || filters.type ? 'Nobody matches.' : 'No admissions yet.'}</p>
                    </div>
                ) : (
                    <>
                        <div className="mt-4 overflow-x-auto rounded-xl border border-border bg-card">
                            <table className="w-full border-separate border-spacing-0 text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-muted-foreground">
                                        <th className="sticky left-0 z-10 min-w-44 border-b border-border bg-card px-3 py-2.5 font-medium">Attendee</th>
                                        {days.map((d) => (
                                            <th key={d.date} className={cn('border-b border-border px-2 py-2.5 text-center font-medium whitespace-nowrap', d.date === filters.day && 'bg-muted/60')}>{d.label}</th>
                                        ))}
                                        <th className="border-b border-border px-3 py-2.5 text-right font-medium whitespace-nowrap">Days</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.data.map((r) => (
                                        <tr key={r.id}>
                                            <td className="sticky left-0 z-10 border-b border-border/60 bg-card px-3 py-2 align-top">
                                                <div className="max-w-56 font-medium [overflow-wrap:anywhere]">{r.name}</div>
                                                <div className="max-w-56 text-xs text-muted-foreground [overflow-wrap:anywhere]">{[r.type, r.email].filter(Boolean).join(' · ')}</div>
                                            </td>
                                            {days.map((d) => (
                                                <td key={d.date} className={cn('border-b border-border/60 px-2 py-2 text-center', d.date === filters.day && 'bg-muted/60')}>
                                                    {r.days[d.date] ? (
                                                        <span className="inline-flex flex-col items-center" title={`Checked in ${d.label}, ${r.days[d.date]}`}>
                                                            <Check className="size-4 text-[#2ec4b6]" strokeWidth={3} />
                                                            <span className="text-[10px] text-muted-foreground tabular-nums">{r.days[d.date]}</span>
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground/50" aria-label="Not checked in">–</span>
                                                    )}
                                                </td>
                                            ))}
                                            <td className="border-b border-border/60 px-3 py-2 text-right font-semibold tabular-nums">{r.attended}{days.length > 0 && <span className="text-xs font-normal text-muted-foreground">/{days.length}</span>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                            <span className="text-muted-foreground">{rows.from}–{rows.to} of {rows.total.toLocaleString()}</span>
                            <div className="flex flex-wrap gap-1">
                                {rows.links.map((l, i) => (
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

Attendance.layout = {
    breadcrumbs: [
        { title: 'Events', href: '/host/events' },
        { title: 'Attendance', href: '#' },
    ],
};
