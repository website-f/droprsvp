import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Ban, ExternalLink, Pencil, RotateCcw } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface EventDetail {
    slug: string; title: string; subtitle: string | null; description: string | null; cover_image: string | null;
    status: string; cancelled_reason: string | null; visibility: string; category: string | null; city: string | null;
    is_online: boolean; venue_name: string | null; venue_address: string | null; when: string | null;
    organizer: { name: string | null; email: string | null };
    sold: number; revenue: number;
    ticket_types: { name: string; kind: string; price: number; quantity: number | null }[];
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex justify-between gap-4 py-2 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{children}</span>
        </div>
    );
}

export default function AdminEventShow({ event }: { event: EventDetail }) {
    const confirm = useConfirm();
    const cancelled = event.status === 'cancelled';
    const form = useForm({ reason: '' });

    const doCancel = async () => {
        if (!(await confirm({ title: 'Cancel this event?', description: 'It will be marked cancelled and hidden from the public marketplace. The organizer keeps their data.', confirmText: 'Cancel event', destructive: true }))) {
return;
}

        form.post(`/admin/all-events/${event.slug}/cancel`, { preserveScroll: true });
    };

    const doRestore = async () => {
        if (!(await confirm({ title: 'Restore to draft?', description: 'The event returns to the organizer as a draft so they can revise and re-publish it.', confirmText: 'Restore' }))) {
return;
}

        router.post(`/admin/all-events/${event.slug}/restore`, {}, { preserveScroll: true });
    };

    const statusVariant = event.status === 'published' ? 'default' : cancelled ? 'destructive' : 'secondary';

    return (
        <>
            <Head title={`${event.title} — moderation`} />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6 flex flex-wrap items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/admin/all-events" aria-label="Back"><ArrowLeft className="size-4" /></Link></Button>
                    <h1 className="text-2xl font-bold tracking-tight">{event.title}</h1>
                    <Badge variant={statusVariant} className="capitalize">{event.status}</Badge>
                    <div className="ml-auto flex gap-2">
                        <Button asChild variant="outline" size="sm"><Link href={`/host/events/${event.slug}/edit`}><Pencil className="size-4" /> Edit event</Link></Button>
                        <Button asChild variant="ghost" size="sm"><a href={`/en-my/e/${event.slug}`} target="_blank" rel="noreferrer"><ExternalLink className="size-4" /> View</a></Button>
                    </div>
                </div>

                {event.cover_image && <img src={event.cover_image} alt="" className="mb-6 aspect-[16/6] w-full rounded-2xl border border-border object-cover" />}

                <div className="grid gap-6 md:grid-cols-2">
                    {/* Details */}
                    <section className="rounded-xl border border-border bg-card p-5">
                        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Details</h2>
                        <div className="divide-y divide-border">
                            <Row label="Organizer">{event.organizer.name ?? '—'}</Row>
                            <Row label="Contact">{event.organizer.email ?? '—'}</Row>
                            <Row label="Category">{event.category ?? '—'}</Row>
                            <Row label="Visibility"><span className="capitalize">{event.visibility}</span></Row>
                            <Row label="When">{event.when ?? 'No date set'}</Row>
                            <Row label="Location">{event.is_online ? 'Online' : [event.venue_name, event.city].filter(Boolean).join(' · ') || '—'}</Row>
                        </div>
                    </section>

                    {/* Performance */}
                    <section className="rounded-xl border border-border bg-card p-5">
                        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Performance</h2>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="rounded-lg border border-border p-4"><div className="text-2xl font-bold">{event.sold}</div><div className="text-xs text-muted-foreground">Tickets sold</div></div>
                            <div className="rounded-lg border border-border p-4"><div className="text-2xl font-bold">RM {event.revenue.toFixed(2)}</div><div className="text-xs text-muted-foreground">Revenue</div></div>
                        </div>
                        <div className="mt-4 grid gap-1.5">
                            {event.ticket_types.map((t, i) => (
                                <div key={i} className="flex items-center justify-between rounded-lg border border-border px-3 py-2 text-sm">
                                    <span>{t.name} <span className="text-xs capitalize text-muted-foreground">· {t.kind}</span></span>
                                    <span className="font-medium">{t.kind === 'free' ? 'Free' : `RM ${t.price.toFixed(2)}`}</span>
                                </div>
                            ))}
                        </div>
                    </section>
                </div>

                {event.description && (
                    <section className="mt-6 rounded-xl border border-border bg-card p-5">
                        <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Description</h2>
                        <p className="whitespace-pre-line text-sm leading-relaxed text-foreground/80">{event.description}</p>
                    </section>
                )}

                {/* Moderation */}
                <section className="mt-6 rounded-xl border border-destructive/30 bg-destructive/5 p-5">
                    <h2 className="mb-1 text-sm font-semibold uppercase tracking-wide text-destructive">Moderation</h2>
                    {cancelled ? (
                        <div className="grid gap-3">
                            <p className="text-sm text-muted-foreground">This event is <strong>cancelled</strong>{event.cancelled_reason ? <> — “{event.cancelled_reason}”</> : ''}.</p>
                            <Button variant="outline" className="w-max" onClick={doRestore}><RotateCcw className="size-4" /> Restore to draft</Button>
                        </div>
                    ) : (
                        <div className="grid gap-3">
                            <p className="text-sm text-muted-foreground">If this event breaches policy, cancel it. It will be hidden from the marketplace and marked cancelled on its public page.</p>
                            <div className="grid gap-1.5">
                                <Label htmlFor="reason">Reason (optional, shown to the organizer)</Label>
                                <textarea id="reason" rows={2} className="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="e.g. Prohibited content under section 4 of our policy" />
                            </div>
                            <Button variant="destructive" className="w-max" onClick={doCancel} disabled={form.processing}><Ban className="size-4" /> Cancel event</Button>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

AdminEventShow.layout = {
    breadcrumbs: [{ title: 'All events', href: '/admin/all-events' }],
};
