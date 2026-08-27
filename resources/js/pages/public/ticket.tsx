import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { CalendarDays, MapPin, Video } from 'lucide-react';

interface TicketView {
    qr_token: string;
    attendee_name: string | null;
    status: string;
    type: string | null;
    event: { title: string; slug: string; when: string | null; venue_name: string | null; is_online: boolean };
}

export default function TicketPass({ ticket, qr }: { ticket: TicketView; qr: string }) {
    const checkedIn = ticket.status === 'checked_in';
    const voided = ticket.status === 'void' || ticket.status === 'refunded';

    return (
        <>
            <Head title={`Ticket · ${ticket.event.title}`}>
                <meta name="robots" content="noindex, nofollow" head-key="robots" />
            </Head>

            <div className="flex min-h-screen flex-col items-center bg-muted/40 px-4 py-10 text-foreground">
                <Link href="/" className="mb-6 text-xl font-bold tracking-tight">Drop<span className="text-muted-foreground">RSVP</span></Link>

                <div className="w-full max-w-sm overflow-hidden rounded-3xl border border-border bg-card shadow-sm">
                    {/* Header */}
                    <div className="bg-foreground px-6 py-5 text-background">
                        <div className="text-xs uppercase tracking-widest opacity-70">Admit one</div>
                        <div className="mt-1 text-lg font-bold leading-tight">{ticket.event.title}</div>
                    </div>

                    {/* Details */}
                    <div className="grid gap-2 px-6 py-5 text-sm">
                        {ticket.event.when && <div className="flex items-center gap-2"><CalendarDays className="size-4 text-muted-foreground" />{ticket.event.when}</div>}
                        {ticket.event.is_online
                            ? <div className="flex items-center gap-2"><Video className="size-4 text-muted-foreground" />Online event</div>
                            : ticket.event.venue_name && <div className="flex items-center gap-2"><MapPin className="size-4 text-muted-foreground" />{ticket.event.venue_name}</div>}
                        <div className="mt-2 flex items-center justify-between">
                            <div>
                                <div className="text-xs text-muted-foreground">Attendee</div>
                                <div className="font-medium">{ticket.attendee_name ?? 'Guest'}</div>
                            </div>
                            {ticket.type && <Badge variant="secondary">{ticket.type}</Badge>}
                        </div>
                    </div>

                    {/* Perforation */}
                    <div className="relative flex items-center">
                        <div className="absolute -left-2 size-4 rounded-full bg-muted/40" />
                        <div className="h-px flex-1 border-t border-dashed border-border" />
                        <div className="absolute -right-2 size-4 rounded-full bg-muted/40" />
                    </div>

                    {/* QR */}
                    <div className="flex flex-col items-center gap-3 px-6 py-6">
                        {checkedIn ? (
                            <Badge variant="outline" className="border-foreground">Checked in</Badge>
                        ) : voided ? (
                            <Badge variant="destructive">Not valid</Badge>
                        ) : (
                            <div
                                className="rounded-xl bg-white p-3 [&_svg]:block [&_svg]:size-44"
                                dangerouslySetInnerHTML={{ __html: qr }}
                            />
                        )}
                        <div className="font-mono text-[11px] tracking-wide text-muted-foreground">{ticket.qr_token}</div>
                        <p className="text-center text-xs text-muted-foreground">Show this at the door to check in.</p>
                    </div>
                </div>

                <Link href={`/e/${ticket.event.slug}`} className="mt-6 text-sm text-muted-foreground underline underline-offset-4">View event</Link>
            </div>
        </>
    );
}
