import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArmchairIcon, ArrowLeft, ChevronDown, Hand, Plus, Save, Trash2, Users, Wand2 } from 'lucide-react';
import { useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

interface TableView { id: number; name: string; capacity: number; assigned: number; by_type: Record<string, number> | null }
interface TicketView { id: number; name: string; type: string | null; type_id: number | null; table_id: number | null; chosen: boolean }
interface Props {
    event: { title: string; slug: string; table_choice: boolean; table_choice_limit: number | null; choices_used: number; per_ticket_type: boolean };
    tables: TableView[];
    tickets: TicketView[];
}

const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/**
 * Who is at each table: booked / seats, a bar, and — tapped open — the
 * people themselves. Read straight off the attendee list below, so it is
 * always the same picture. When each ticket type is its own sitting, the
 * count is split per type, since a table holds that many for EACH one.
 */
function TableOverview({ tables, tickets, perType }: { tables: TableView[]; tickets: TicketView[]; perType: boolean }) {
    const [open, setOpen] = useState<number | null>(null);
    const unassigned = tickets.filter((t) => !t.table_id).length;
    const seated = tickets.length - unassigned;
    const seats = tables.reduce((n, t) => n + t.capacity, 0);

    return (
        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
            <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Who’s at each table</h2>
                <span className="text-xs text-muted-foreground">
                    {seated} seated{!perType && seats > 0 ? ` of ${seats} seats` : ''}{unassigned > 0 ? ` · ${unassigned} not seated yet` : ''}
                </span>
            </div>
            <p className="mb-4 text-xs text-muted-foreground">Tap a table to see who is booked there.</p>

            <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(min(100%,15rem),1fr))]">
                {tables.map((table) => {
                    const people = tickets.filter((t) => t.table_id === table.id);
                    const isOpen = open === table.id;
                    // Per sitting, the fullest sitting is the one that limits the table.
                    const sittings = perType
                        ? Object.entries(people.reduce<Record<string, { name: string; n: number }>>((acc, t) => {
                            const key = String(t.type_id ?? 'other');
                            acc[key] = { name: t.type ?? 'Other', n: (acc[key]?.n ?? 0) + 1 };

                            return acc;
                        }, {}))
                        : [];
                    const used = perType ? Math.max(0, ...sittings.map(([, v]) => v.n)) : people.length;
                    const pct = table.capacity ? Math.min(100, Math.round((used / table.capacity) * 100)) : 0;
                    const full = used >= table.capacity;

                    return (
                        <div key={table.id} className={cn('rounded-xl border transition-colors', isOpen ? 'border-foreground/40' : 'border-border')}>
                            <button type="button" onClick={() => setOpen(isOpen ? null : table.id)} aria-expanded={isOpen} className="flex w-full items-start gap-3 p-3 text-left">
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium [overflow-wrap:anywhere]">{table.name}</span>
                                    <span className="mt-0.5 flex items-center gap-1.5 text-sm">
                                        <Users className="size-3.5 text-muted-foreground" />
                                        <span className="font-semibold tabular-nums">{perType ? people.length : `${people.length} / ${table.capacity}`}</span>
                                        <span className="text-xs text-muted-foreground">{perType ? `booked · ${table.capacity} seats per session` : (full ? 'full' : `${table.capacity - people.length} free`)}</span>
                                    </span>
                                    <span className="mt-2 block h-1.5 overflow-hidden rounded-full bg-muted">
                                        <span className={cn('block h-full rounded-full', full ? 'bg-amber-500' : 'bg-[#2ec4b6]')} style={{ width: `${pct}%` }} />
                                    </span>
                                </span>
                                <ChevronDown className={cn('mt-1 size-4 shrink-0 text-muted-foreground transition-transform', isOpen && 'rotate-180')} />
                            </button>

                            {isOpen && (
                                <div className="border-t border-border px-3 py-2">
                                    {perType && sittings.length > 0 && (
                                        <div className="mb-2 flex flex-wrap gap-1.5">
                                            {sittings.map(([key, v]) => (
                                                <span key={key} className="rounded-full bg-muted px-2 py-0.5 text-[11px] [overflow-wrap:anywhere]">{v.name}: {v.n}/{table.capacity}</span>
                                            ))}
                                        </div>
                                    )}
                                    {people.length === 0 ? (
                                        <p className="py-1 text-xs text-muted-foreground">Nobody booked here yet.</p>
                                    ) : (
                                        <ul className="grid gap-1">
                                            {people.map((p) => (
                                                <li key={p.id} className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">
                                                    <span className="[overflow-wrap:anywhere]">{p.name}</span>
                                                    {p.type && <span className="text-xs text-muted-foreground">· {p.type}</span>}
                                                    {p.chosen && <span className="rounded-full bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">Picked</span>}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/** Table editor — keyed on the table ids by the parent so it re-inits after a save. */
function TablesEditor({ slug, initial }: { slug: string; initial: TableView[] }) {
    const form = useForm({
        tables: initial.map((t) => ({ id: t.id as number | undefined, name: t.name, capacity: String(t.capacity) })),
    });
    const { data, setData, processing } = form;

    const patch = (i: number, key: 'name' | 'capacity', val: string) =>
        setData('tables', data.tables.map((t, idx) => (idx === i ? { ...t, [key]: val } : t)));

    return (
        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
            <div className="mb-4 flex items-center justify-between">
                <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Tables</h2>
                <Button type="button" variant="outline" size="sm" onClick={() => setData('tables', [...data.tables, { id: undefined, name: `Table ${data.tables.length + 1}`, capacity: '8' }])}>
                    <Plus className="size-3.5" /> Add table
                </Button>
            </div>

            {data.tables.length === 0 && <p className="mb-3 text-sm text-muted-foreground">No tables yet. Add tables, then seat attendees below.</p>}

            <div className="grid gap-2">
                {data.tables.map((t, i) => (
                    <div key={i} className="grid grid-cols-[1fr_120px_auto] items-end gap-2">
                        <div className="grid gap-1">
                            {i === 0 && <Label className="text-xs">Name</Label>}
                            <input className={field} value={t.name} onChange={(e) => patch(i, 'name', e.target.value)} />
                        </div>
                        <div className="grid gap-1">
                            {i === 0 && <Label className="text-xs">Seats</Label>}
                            <input type="number" min={1} className={field} value={t.capacity} onChange={(e) => patch(i, 'capacity', e.target.value)} />
                        </div>
                        <Button type="button" variant="ghost" size="icon" className="size-10" onClick={() => setData('tables', data.tables.filter((_, idx) => idx !== i))}><Trash2 className="size-4" /></Button>
                    </div>
                ))}
            </div>

            <Button type="button" className="mt-4" disabled={processing} onClick={() => form.post(`/host/events/${slug}/seating/tables`, { preserveScroll: true })}>
                <Save className="size-4" /> Save tables
            </Button>
        </div>
    );
}

export default function Seating({ event, tables, tickets }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const tablesKey = tables.map((t) => t.id).join('-') || 'empty';

    const assign = (ticketId: number, tableId: string) =>
        router.post(`/host/events/${event.slug}/seating/assign`, { ticket_id: ticketId, seating_table_id: tableId || null }, { preserveScroll: true });

    return (
        <>
            <Head title={`Seating · ${event.title}`} />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/host/events"><ArrowLeft className="size-4" /></Link></Button>
                    <div>
                        <h1 className="text-xl font-bold tracking-tight">Seating</h1>
                        <p className="text-sm text-muted-foreground">{event.title}</p>
                    </div>
                </div>

                {event.table_choice && (
                    <div className="mb-4 flex items-start gap-2 rounded-xl border border-border bg-muted/40 p-3 text-sm">
                        <Hand className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <span>
                            Buyers choose their own table at checkout
                            {event.table_choice_limit !== null
                                ? <> — <strong>{Math.min(event.choices_used, event.table_choice_limit)} of the first {event.table_choice_limit}</strong> picks used.</>
                                : <> — <strong>{event.choices_used}</strong> order{event.choices_used === 1 ? '' : 's'} picked so far.</>}
                            {' '}You can still move anyone below. Change this in the event’s Ticketing settings.
                        </span>
                    </div>
                )}

                {errors?.seating && <div className="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{errors.seating}</div>}

                {tables.length > 0 && <div className="mb-6"><TableOverview tables={tables} tickets={tickets} perType={event.per_ticket_type} /></div>}

                <div className="mb-6"><TablesEditor key={tablesKey} slug={event.slug} initial={tables} /></div>

                {/* Assign attendees */}
                <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Seat attendees</h2>
                        {tables.length > 0 && tickets.length > 0 && (
                            <Button type="button" variant="outline" size="sm" onClick={() => router.post(`/host/events/${event.slug}/seating/auto-assign`, {}, { preserveScroll: true })}>
                                <Wand2 className="size-3.5" /> Auto-assign
                            </Button>
                        )}
                    </div>
                    {tickets.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No attendees yet — tickets appear here once sold.</p>
                    ) : tables.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Add a table first, then assign attendees.</p>
                    ) : (
                        <ul className="grid gap-2">
                            {tickets.map((t) => (
                                <li key={t.id} className="flex items-center justify-between gap-3 border-b border-border/60 py-2 last:border-0">
                                    <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                        <ArmchairIcon className="size-4 shrink-0 text-muted-foreground" />
                                        <span className="font-medium">{t.name}</span>
                                        {t.type && <span className="text-xs text-muted-foreground">· {t.type}</span>}
                                        {t.chosen && <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">Picked by buyer</span>}
                                    </div>
                                    <div className="w-44 shrink-0 sm:w-72">
                                        <AppSelect
                                            value={t.table_id ? String(t.table_id) : 'none'}
                                            onChange={(v) => assign(t.id, v === 'none' ? '' : v)}
                                            options={[
                                                { value: 'none', label: '— Unassigned —' },
                                                ...tables.map((tb) => {
                                                    // Per sitting when each ticket type has the tables to itself.
                                                    const used = tb.by_type ? (tb.by_type[String(t.type_id)] ?? 0) : tb.assigned;

                                                    return { value: String(tb.id), label: `${tb.name} (${used}/${tb.capacity})`, disabled: used >= tb.capacity && t.table_id !== tb.id };
                                                }),
                                            ]}
                                            className="h-10"
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

Seating.layout = {
    breadcrumbs: [
        { title: 'Events', href: '/host/events' },
        { title: 'Seating', href: '#' },
    ],
};
