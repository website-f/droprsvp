import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { AppSelect } from '@/components/ui/app-select';
import { DateTimePicker } from '@/components/ui/date-time-picker';
import { uploadImage } from '@/lib/upload';
import { ArrowLeft, ImagePlus, Plus, Trash2 } from 'lucide-react';

interface Category { id: number; name: string }
interface SessionRow { id?: number; title: string; starts_at: string; ends_at: string; capacity: string }
interface TicketRow {
    id?: number; name: string; description: string; kind: 'paid' | 'free' | 'donation';
    price: string; quantity: string; min_per_order: string; max_per_order: string;
    sales_start: string; sales_end: string; is_active: boolean;
}
interface EventProp {
    slug: string; title: string; subtitle: string | null; category_id: number | null;
    description: string | null; cover_image: string | null; visibility: string; timezone: string;
    is_online: boolean; venue_name: string | null; venue_address: string | null; online_url: string | null;
    capacity: number | null;
    sessions: Array<{ id: number; title: string | null; starts_at: string | null; ends_at: string | null; capacity: number | null }>;
    ticket_types: Array<{ id: number; name: string; description: string | null; kind: TicketRow['kind']; price: string; quantity: number | null; min_per_order: number; max_per_order: number; sales_start: string | null; sales_end: string | null; is_active: boolean }>;
}

const field = 'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const dt = (v: string | null | undefined) => (v ? v.slice(0, 16) : '');

const emptySession = (): SessionRow => ({ title: '', starts_at: '', ends_at: '', capacity: '' });
const emptyTicket = (): TicketRow => ({ name: '', description: '', kind: 'paid', price: '0', quantity: '', min_per_order: '1', max_per_order: '10', sales_start: '', sales_end: '', is_active: true });

export default function EventForm({ event, categories }: { event: EventProp | null; categories: Category[] }) {
    const isEdit = !!event;

    const form = useForm({
        title: event?.title ?? '',
        subtitle: event?.subtitle ?? '',
        category_id: event?.category_id ? String(event.category_id) : '',
        description: event?.description ?? '',
        cover_image: event?.cover_image ?? '',
        visibility: event?.visibility ?? 'public',
        timezone: event?.timezone ?? 'Asia/Kuala_Lumpur',
        is_online: event?.is_online ?? false,
        venue_name: event?.venue_name ?? '',
        venue_address: event?.venue_address ?? '',
        online_url: event?.online_url ?? '',
        capacity: event?.capacity ? String(event.capacity) : '',
        publish: false,
        sessions: (event?.sessions ?? []).map((s): SessionRow => ({ id: s.id, title: s.title ?? '', starts_at: dt(s.starts_at), ends_at: dt(s.ends_at), capacity: s.capacity != null ? String(s.capacity) : '' })),
        ticketTypes: (event?.ticket_types ?? []).map((t): TicketRow => ({ id: t.id, name: t.name, description: t.description ?? '', kind: t.kind, price: String(t.price), quantity: t.quantity != null ? String(t.quantity) : '', min_per_order: String(t.min_per_order), max_per_order: String(t.max_per_order), sales_start: dt(t.sales_start), sales_end: dt(t.sales_end), is_active: t.is_active })),
    });
    const { data, setData, errors, processing } = form;
    const coverRef = useRef<HTMLInputElement>(null);
    const [uploadingCover, setUploadingCover] = useState(false);

    const onPickCover = async (file: File | undefined) => {
        if (!file) return;
        setUploadingCover(true);
        try {
            setData('cover_image', await uploadImage(file));
        } catch {
            /* keep the existing value on failure */
        } finally {
            setUploadingCover(false);
        }
    };

    const patchSession = (i: number, key: keyof SessionRow, val: string) =>
        setData('sessions', data.sessions.map((s, idx) => (idx === i ? { ...s, [key]: val } : s)));
    const patchTicket = (i: number, key: keyof TicketRow, val: string | boolean) =>
        setData('ticketTypes', data.ticketTypes.map((t, idx) => (idx === i ? { ...t, [key]: val } : t)));

    const save = (publish: boolean) => {
        form.transform((d) => ({ ...d, publish }));
        if (isEdit) form.put(`/host/events/${event!.slug}`);
        else form.post('/host/events');
    };

    return (
        <>
            <Head title={isEdit ? 'Edit event' : 'Create event'} />
            <form onSubmit={(e) => { e.preventDefault(); save(false); }} className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/host/events"><ArrowLeft className="size-4" /></Link></Button>
                    <h1 className="text-2xl font-bold tracking-tight">{isEdit ? 'Edit event' : 'Create event'}</h1>
                </div>

                {/* Details */}
                <section className="mb-6 rounded-xl border border-border bg-card p-5">
                    <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Details</h2>
                    <div className="grid gap-4">
                        {/* Cover image */}
                        <div className="grid gap-1.5">
                            <Label>Cover image</Label>
                            {data.cover_image
                                ? (
                                    <div className="relative overflow-hidden rounded-lg border border-border">
                                        <img src={data.cover_image} alt="" className="aspect-[16/9] w-full object-cover" />
                                        <div className="absolute right-2 top-2 flex gap-2">
                                            <Button type="button" size="sm" variant="secondary" disabled={uploadingCover} onClick={() => coverRef.current?.click()}>Replace</Button>
                                            <Button type="button" size="sm" variant="secondary" onClick={() => setData('cover_image', '')}>Remove</Button>
                                        </div>
                                    </div>
                                )
                                : (
                                    <button type="button" onClick={() => coverRef.current?.click()} disabled={uploadingCover}
                                        className="flex aspect-[16/9] w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border text-sm text-muted-foreground transition-colors hover:border-foreground/40 hover:text-foreground">
                                        <ImagePlus className="size-6" />
                                        {uploadingCover ? 'Uploading…' : 'Upload a cover image (shown on cards & the event page)'}
                                    </button>
                                )}
                            <input ref={coverRef} type="file" accept="image/*" hidden onChange={(e) => onPickCover(e.target.files?.[0])} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="title">Event title</Label>
                            <input id="title" className={field} value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="e.g. KL Indie Music Night" />
                            {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="subtitle">Subtitle</Label>
                            <input id="subtitle" className={field} value={data.subtitle} onChange={(e) => setData('subtitle', e.target.value)} placeholder="A short one-liner" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="category">Category</Label>
                                <AppSelect
                                    id="category"
                                    value={data.category_id || 'none'}
                                    onChange={(v) => setData('category_id', v === 'none' ? '' : v)}
                                    options={[{ value: 'none', label: '— None —' }, ...categories.map((c) => ({ value: String(c.id), label: c.name }))]}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="visibility">Visibility</Label>
                                <AppSelect
                                    id="visibility"
                                    value={data.visibility}
                                    onChange={(v) => setData('visibility', v)}
                                    options={[{ value: 'public', label: 'Public' }, { value: 'unlisted', label: 'Unlisted' }, { value: 'private', label: 'Private' }]}
                                />
                            </div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="description">Description</Label>
                            <textarea id="description" rows={4} className={field + ' h-auto py-2'} value={data.description} onChange={(e) => setData('description', e.target.value)} placeholder="Tell attendees what to expect…" />
                        </div>
                    </div>
                </section>

                {/* Location */}
                <section className="mb-6 rounded-xl border border-border bg-card p-5">
                    <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Location</h2>
                    <label className="mb-4 flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={data.is_online} onChange={(e) => setData('is_online', e.target.checked)} className="size-4 rounded border-input" />
                        This is an online event
                    </label>
                    {data.is_online ? (
                        <div className="grid gap-1.5">
                            <Label htmlFor="online_url">Online URL</Label>
                            <input id="online_url" className={field} value={data.online_url} onChange={(e) => setData('online_url', e.target.value)} placeholder="https://…" />
                            {errors.online_url && <p className="text-xs text-destructive">{errors.online_url}</p>}
                        </div>
                    ) : (
                        <div className="grid gap-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="venue_name">Venue name</Label>
                                <input id="venue_name" className={field} value={data.venue_name} onChange={(e) => setData('venue_name', e.target.value)} placeholder="e.g. The Bee, Publika" />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="venue_address">Address</Label>
                                <input id="venue_address" className={field} value={data.venue_address} onChange={(e) => setData('venue_address', e.target.value)} />
                            </div>
                        </div>
                    )}
                </section>

                {/* Sessions */}
                <section className="mb-6 rounded-xl border border-border bg-card p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Dates & sessions</h2>
                        <Button type="button" variant="outline" size="sm" onClick={() => setData('sessions', [...data.sessions, emptySession()])}><Plus className="size-3.5" /> Add session</Button>
                    </div>
                    {data.sessions.length === 0 && <p className="text-sm text-muted-foreground">Add at least one date. Add more for a recurring or multi-session event.</p>}
                    <div className="grid gap-4">
                        {data.sessions.map((s, i) => (
                            <div key={i} className="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                <div className="grid gap-1.5">
                                    <Label>Starts</Label>
                                    <DateTimePicker value={s.starts_at} onChange={(v) => patchSession(i, 'starts_at', v)} />
                                    {errors[`sessions.${i}.starts_at` as keyof typeof errors] && <p className="text-xs text-destructive">Required</p>}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>Ends</Label>
                                    <DateTimePicker value={s.ends_at} onChange={(v) => patchSession(i, 'ends_at', v)} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>Capacity</Label>
                                    <input type="number" min={0} className={field} value={s.capacity} onChange={(e) => patchSession(i, 'capacity', e.target.value)} placeholder="∞" />
                                </div>
                                <div className="flex items-end">
                                    <Button type="button" variant="ghost" size="icon" onClick={() => setData('sessions', data.sessions.filter((_, idx) => idx !== i))}><Trash2 className="size-4" /></Button>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Ticket types */}
                <section className="mb-6 rounded-xl border border-border bg-card p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Tickets</h2>
                        <Button type="button" variant="outline" size="sm" onClick={() => setData('ticketTypes', [...data.ticketTypes, emptyTicket()])}><Plus className="size-3.5" /> Add ticket type</Button>
                    </div>
                    {data.ticketTypes.length === 0 && <p className="text-sm text-muted-foreground">Add a ticket type — free RSVP, paid, or donation.</p>}
                    <div className="grid gap-4">
                        {data.ticketTypes.map((t, i) => (
                            <div key={i} className="grid gap-3 rounded-lg border border-border p-3">
                                <div className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto]">
                                    <div className="grid gap-1.5">
                                        <Label>Name</Label>
                                        <input className={field} value={t.name} onChange={(e) => patchTicket(i, 'name', e.target.value)} placeholder="e.g. Early Bird" />
                                        {errors[`ticketTypes.${i}.name` as keyof typeof errors] && <p className="text-xs text-destructive">Required</p>}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Type</Label>
                                        <AppSelect
                                            value={t.kind}
                                            onChange={(v) => patchTicket(i, 'kind', v)}
                                            options={[{ value: 'paid', label: 'Paid' }, { value: 'free', label: 'Free' }, { value: 'donation', label: 'Donation' }]}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Price (RM)</Label>
                                        <input type="number" min={0} step="0.01" className={field} value={t.price} disabled={t.kind === 'free'} onChange={(e) => patchTicket(i, 'price', e.target.value)} />
                                    </div>
                                    <div className="flex items-end">
                                        <Button type="button" variant="ghost" size="icon" onClick={() => setData('ticketTypes', data.ticketTypes.filter((_, idx) => idx !== i))}><Trash2 className="size-4" /></Button>
                                    </div>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-4">
                                    <div className="grid gap-1.5">
                                        <Label>Quantity</Label>
                                        <input type="number" min={0} className={field} value={t.quantity} onChange={(e) => patchTicket(i, 'quantity', e.target.value)} placeholder="∞" />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Min / order</Label>
                                        <input type="number" min={1} className={field} value={t.min_per_order} onChange={(e) => patchTicket(i, 'min_per_order', e.target.value)} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Max / order</Label>
                                        <input type="number" min={1} className={field} value={t.max_per_order} onChange={(e) => patchTicket(i, 'max_per_order', e.target.value)} />
                                    </div>
                                    <label className="flex items-end gap-2 pb-2.5 text-sm">
                                        <input type="checkbox" checked={t.is_active} onChange={(e) => patchTicket(i, 'is_active', e.target.checked)} className="size-4 rounded border-input" />
                                        Active
                                    </label>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Actions */}
                <div className="flex items-center justify-end gap-3">
                    <Button type="button" variant="outline" disabled={processing} onClick={() => save(false)}>Save draft</Button>
                    <Button type="button" disabled={processing} onClick={() => save(true)}>Publish</Button>
                </div>
            </form>
        </>
    );
}

EventForm.layout = {
    breadcrumbs: [
        { title: 'Events', href: '/host/events' },
        { title: 'Builder', href: '/host/events/create' },
    ],
};
