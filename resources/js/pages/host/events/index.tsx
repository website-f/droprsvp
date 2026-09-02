import { Head, Link, router } from '@inertiajs/react';
import { ArmchairIcon, CalendarDays, ChartColumn, ImagePlus, MoreHorizontal, Pencil, Plus, Receipt, Rocket, ScanLine, Ticket, Trash2, Users } from 'lucide-react';
import { useConfirm } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

interface HostEvent {
    id: number;
    title: string;
    slug: string;
    status: string;
    visibility: string;
    starts_at: string | null;
    ticket_types_count: number;
    sessions_count: number;
    orders_count: number;
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
                                        <Button asChild variant="outline" size="sm"><Link href={`/host/events/${e.slug}/attendees`}><Users className="size-3.5" /> <span className="hidden sm:inline">Attendees</span></Link></Button>
                                        <Button asChild variant="outline" size="sm"><Link href={`/host/events/${e.slug}/edit`}><Pencil className="size-3.5" /> <span className="hidden sm:inline">Edit</span></Link></Button>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" aria-label="More actions"><MoreHorizontal className="size-4" /></Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" className="w-48">
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/attendees?scan=1`}><ScanLine className="size-4" /> Check-in (scan)</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/orders`}><Receipt className="size-4" /> Orders</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/analytics`}><ChartColumn className="size-4" /> Analytics</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/seating`}><ArmchairIcon className="size-4" /> Tables &amp; seating</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/photos`}><ImagePlus className="size-4" /> Photos</Link></DropdownMenuItem>
                                                <DropdownMenuItem asChild><Link href={`/host/events/${e.slug}/promote`}><Rocket className="size-4" /> Promote</Link></DropdownMenuItem>
                                                <DropdownMenuSeparator />
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
        </>
    );
}

EventsIndex.layout = {
    breadcrumbs: [{ title: 'Events', href: '/host/events' }],
};
