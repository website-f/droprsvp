import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ArmchairIcon, ArrowLeft, Plus, Save, Trash2 } from 'lucide-react';

interface TableView { id: number; name: string; capacity: number; assigned: number }
interface TicketView { id: number; name: string; type: string | null; table_id: number | null }
interface Props {
    event: { title: string; slug: string };
    tables: TableView[];
    tickets: TicketView[];
}

const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

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

                {errors?.seating && <div className="mb-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{errors.seating}</div>}

                <div className="mb-6"><TablesEditor key={tablesKey} slug={event.slug} initial={tables} /></div>

                {/* Assign attendees */}
                <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Seat attendees</h2>
                    {tickets.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No attendees yet — tickets appear here once sold.</p>
                    ) : tables.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Add a table first, then assign attendees.</p>
                    ) : (
                        <ul className="grid gap-2">
                            {tickets.map((t) => (
                                <li key={t.id} className="flex items-center justify-between gap-3 border-b border-border/60 py-2 last:border-0">
                                    <div className="flex items-center gap-2 text-sm">
                                        <ArmchairIcon className="size-4 text-muted-foreground" />
                                        <span className="font-medium">{t.name}</span>
                                        {t.type && <span className="text-xs text-muted-foreground">· {t.type}</span>}
                                    </div>
                                    <select className={`${field} w-48`} value={t.table_id ?? ''} onChange={(e) => assign(t.id, e.target.value)}>
                                        <option value="">— Unassigned —</option>
                                        {tables.map((tb) => (
                                            <option key={tb.id} value={tb.id} disabled={tb.assigned >= tb.capacity && t.table_id !== tb.id}>
                                                {tb.name} ({tb.assigned}/{tb.capacity})
                                            </option>
                                        ))}
                                    </select>
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
