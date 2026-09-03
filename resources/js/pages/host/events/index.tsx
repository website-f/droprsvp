import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArmchairIcon, CalendarDays, ChartColumn, Copy, Gavel, ImagePlus, MoreHorizontal, Paperclip, Pencil, Plus, Receipt, Rocket, ScanLine, Tag, Ticket, Trash2, Users, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { uploadImage } from '@/lib/upload';

interface HostEvent {
    id: number;
    title: string;
    slug: string;
    status: string;
    visibility: string;
    appeal_status?: string | null;
    starts_at: string | null;
    ticket_types_count: number;
    sessions_count: number;
    orders_count: number;
}

/** Appeal an admin cancellation — reason + proof attachments, both required. */
function ReappealDialog({ event, onClose }: { event: HostEvent | null; onClose: () => void }) {
    const form = useForm<{ reason: string; attachments: string[] }>({ reason: '', attachments: [] });
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const close = () => {
 form.reset(); form.clearErrors(); onClose(); 
};

    const addFiles = async (files: FileList | null) => {
        if (!files) {
            return;
        }

        setUploading(true);

        try {
            const urls: string[] = [];

            for (const f of Array.from(files)) {
                urls.push(await uploadImage(f));
            }

            form.setData('attachments', [...form.data.attachments, ...urls].slice(0, 6));
        } finally {
            setUploading(false);
        }
    };

    const submit = () => {
        if (event) {
            form.post(`/host/events/${event.slug}/reappeal`, { preserveScroll: true, onSuccess: close });
        }
    };

    return (
        <Dialog open={!!event} onOpenChange={(o) => !o && close()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader><DialogTitle>Appeal cancellation</DialogTitle></DialogHeader>
                <div className="grid gap-3">
                    <p className="text-sm text-muted-foreground">Tell us why <strong>{event?.title}</strong> should be restored, and attach proof (permits, licences, screenshots). Both are required.</p>
                    <div className="grid gap-1.5">
                        <textarea rows={4} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="Explain why this event follows our policy…" className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" />
                        {form.errors.reason && <p className="text-xs text-destructive">{form.errors.reason}</p>}
                    </div>
                    <div className="grid gap-2">
                        <div className="flex flex-wrap gap-2">
                            {form.data.attachments.map((src, i) => (
                                <div key={i} className="relative">
                                    <img src={src} alt="" className="size-16 rounded-lg border border-border object-cover" />
                                    <button type="button" onClick={() => form.setData('attachments', form.data.attachments.filter((_, idx) => idx !== i))} className="absolute -right-1.5 -top-1.5 flex size-5 items-center justify-center rounded-full bg-foreground text-background" aria-label="Remove"><X className="size-3" /></button>
                                </div>
                            ))}
                            {form.data.attachments.length < 6 && (
                                <button type="button" onClick={() => fileRef.current?.click()} disabled={uploading} className="flex size-16 flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-border text-[10px] text-muted-foreground hover:border-foreground/40">
                                    <Paperclip className="size-4" /> {uploading ? '…' : 'Add'}
                                </button>
                            )}
                        </div>
                        <input ref={fileRef} type="file" accept="image/*" multiple className="hidden" onChange={(e) => addFiles(e.target.files)} />
                        {form.errors.attachments && <p className="text-xs text-destructive">At least one attachment is required.</p>}
                    </div>
                </div>
                <DialogFooter className="gap-2 sm:gap-2">
                    <Button variant="outline" onClick={close}>Cancel</Button>
                    <Button onClick={submit} disabled={form.processing || uploading || !form.data.reason.trim() || form.data.attachments.length === 0}>Submit appeal</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function statusTone(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'published') {
        return 'default';
    }

    if (status === 'cancelled') {
        return 'destructive';
    }

    return 'secondary';
}

function formatDate(value: string | null): string {
    if (!value) {
        return 'No date set';
    }

    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export default function EventsIndex({ events }: { events: HostEvent[] }) {
    const confirm = useConfirm();
    const [appealEvent, setAppealEvent] = useState<HostEvent | null>(null);
    const duplicate = (e: HostEvent) => router.post(`/host/events/${e.slug}/duplicate`, {}, { preserveScroll: true });
    const remove = async (e: HostEvent) => {
        if (await confirm({ title: `Delete “${e.title}”?`, description: 'This cannot be undone.', confirmText: 'Delete', destructive: true })) {
            router.delete(`/host/events/${e.slug}`, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Events" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6 flex items-center justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-bold tracking-tight">Your events</h1>
                        <p className="text-sm text-muted-foreground">Create and manage the events you host.</p>
                    </div>
                    <Button asChild className="shrink-0">
                        <Link href="/host/events/create"><Plus className="size-4" /> Create event</Link>
                    </Button>
                </div>

                {events.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border py-16 text-center">
                        <CalendarDays className="size-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">You haven't created any events yet.</p>
                        <Button asChild><Link href="/host/events/create"><Plus className="size-4" /> Create your first event</Link></Button>
                    </div>
                ) : (
                    <div className="grid gap-3">
                        {events.map((e) => (
                            <div key={e.id} className="rounded-2xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-foreground/20">
                                <div className="flex items-start gap-3">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link href={`/host/events/${e.slug}/edit`} className="truncate text-base font-semibold hover:underline">{e.title}</Link>
                                            <Badge variant={statusTone(e.status)} className="shrink-0 capitalize">{e.status}</Badge>
                                        </div>
                                        <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                            <span className="flex items-center gap-1"><CalendarDays className="size-3.5" /> {formatDate(e.starts_at)}</span>
                                            <span className="flex items-center gap-1"><Ticket className="size-3.5" /> {e.ticket_types_count} ticket type{e.ticket_types_count === 1 ? '' : 's'}</span>
                                            <span className="capitalize">{e.visibility}</span>
                                            <span>{e.orders_count} order{e.orders_count === 1 ? '' : 's'}</span>
                                        </div>
                                    </div>

                                    {/* Actions: primary Edit + a tidy "More" dropdown */}
                                    <div className="flex shrink-0 items-center gap-2">
                                        {e.status === 'cancelled' && (
                                            e.appeal_status === 'pending'
                                                ? <Badge variant="secondary" className="shrink-0">Appeal pending</Badge>
                                                : <Button variant="outline" size="sm" onClick={() => setAppealEvent(e)}><Gavel className="size-3.5" /> <span className="hidden sm:inline">Appeal</span></Button>
                                        )}
                                        <Button asChild variant="outline" size="sm"><Link href={`/host/events/${e.slug}/attendees`}><Users className="size-3.5" /> <span className="hidden sm:inline">Attendees</span></Link></Button>
                                        <Button asChild variant="outline" size="sm"><Link href={`/host/events/${e.slug}/edit`}><Pencil className="size-3.5" /> <span className="hidden sm:inline">Edit</span></Link></Button>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" aria-label="More actions"><MoreHorizontal className="size-4" /></Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" className="w-48">
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/attendees?scan=1`}><ScanLine className="size-4" /> Check-in (scan)</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/orders`}><Receipt className="size-4" /> Orders</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/discounts`}><Tag className="size-4" /> Promo codes</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/analytics`}><ChartColumn className="size-4" /> Analytics</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/seating`}><ArmchairIcon className="size-4" /> Tables &amp; seating</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/photos`}><ImagePlus className="size-4" /> Photos</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/promote`}><Rocket className="size-4" /> Promote</Link></DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem onSelect={() => window.setTimeout(() => duplicate(e), 10)}><Copy className="size-4" /> Duplicate</DropdownMenuItem>
                                                <DropdownMenuItem variant="destructive" onSelect={() => window.setTimeout(() => remove(e), 10)}><Trash2 className="size-4" /> Delete</DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <ReappealDialog event={appealEvent} onClose={() => setAppealEvent(null)} />
        </>
    );
}

EventsIndex.layout = {
    breadcrumbs: [{ title: 'Events', href: '/host/events' }],
};
