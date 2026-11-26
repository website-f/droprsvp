import { Head, Link } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight, Download, MapPin, Video } from 'lucide-react';
import { useState } from 'react';
import { AddToCalendar } from '@/components/add-to-calendar';
import { LogoMark } from '@/components/brand';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { region } from '@/lib/routes';

interface TicketView {
    qr_token: string;
    attendee_name: string | null;
    status: string;
    type: string | null;
    table: string | null;
    seat: string | null;
    organizer: string | null;
    event: { title: string; slug: string; when: string | null; venue_name: string | null; is_online: boolean; google_url: string | null; ics_url: string | null };
}

/** One person's pass from the same order: each guest has their own QR. */
interface Pass { qr_token: string; attendee_name: string | null; status: string; type: string | null; table: string | null; seat: string | null; qr: string }

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';

export default function TicketPass({ ticket: opened, qr: openedQr, passes = [] }: { ticket: TicketView; qr: string; passes?: Pass[] }) {
    // Every pass this person holds from the order, starting on the one opened.
    const all: Pass[] = passes.length ? passes : [{ ...opened, qr: openedQr }];
    const [index, setIndex] = useState(() => Math.max(0, all.findIndex((p) => p.qr_token === opened.qr_token)));
    const pass = all[index] ?? all[0];
    // The address follows the pass on screen, so copying the link shares
    // this guest's ticket — not the one that happened to be opened.
    const go = (i: number) => {
        setIndex(i);
        window.history.replaceState(window.history.state, '', `/tickets/${all[i].qr_token}`);
    };
    const ticket = { ...opened, ...pass };
    const qr = pass.qr;
    const many = all.length > 1;
    const checkedIn = ticket.status === 'checked_in';
    const voided = ticket.status === 'void' || ticket.status === 'refunded';

    return (
        <>
            <Head title={`Ticket · ${ticket.event.title}`}>
                <meta name="robots" content="noindex, nofollow" head-key="robots" />
            </Head>

            <div className="flex min-h-screen flex-col items-center bg-muted/40 px-4 py-10 text-foreground">
                {/* Organizer branding */}
                <div className="mb-6 flex items-center gap-3">
                    {ticket.organizer && <span className="flex size-11 items-center justify-center rounded-full bg-foreground text-sm font-bold text-background">{initials(ticket.organizer)}</span>}
                    <div className="text-left">
                        <div className="text-xs text-muted-foreground">Presented by</div>
                        <div className="font-semibold leading-tight">{ticket.organizer ?? 'DropRSVP'}</div>
                    </div>
                </div>

                {many && (
                    <div className="mb-4 w-full max-w-sm print:hidden">
                        <div className="flex items-center justify-between gap-2 rounded-2xl border border-border bg-card p-1.5">
                            <button type="button" aria-label="Previous ticket" disabled={index === 0} onClick={() => go(index - 1)}
                                className="flex size-9 items-center justify-center rounded-xl hover:bg-accent disabled:opacity-30"><ChevronLeft className="size-4" /></button>
                            <div className="text-center text-sm">
                                <div className="font-semibold">Ticket {index + 1} of {all.length}</div>
                                <div className="text-xs text-muted-foreground">One QR per person</div>
                            </div>
                            <button type="button" aria-label="Next ticket" disabled={index === all.length - 1} onClick={() => go(index + 1)}
                                className="flex size-9 items-center justify-center rounded-xl hover:bg-accent disabled:opacity-30"><ChevronRight className="size-4" /></button>
                        </div>
                        <div className="mt-2 flex justify-center gap-1.5" aria-hidden>
                            {all.map((p, i) => <span key={p.qr_token} className={`size-1.5 rounded-full ${i === index ? 'bg-foreground' : 'bg-foreground/20'}`} />)}
                        </div>
                    </div>
                )}

                <div className="w-full max-w-sm overflow-hidden rounded-3xl border border-border bg-card shadow-sm">
                    {/* Header */}
                    <div className="bg-foreground px-6 py-5 text-background">
                        <div className="text-xs uppercase tracking-widest opacity-70">Admit one{many ? ` · ${index + 1} of ${all.length}` : ''}</div>
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
                            <div className="flex flex-wrap items-center justify-end gap-2">
                                {ticket.seat && <Badge>{ticket.seat}</Badge>}
                                {ticket.table && <Badge>{ticket.table}</Badge>}
                                {ticket.type && <Badge variant="secondary">{ticket.type}</Badge>}
                            </div>
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
                        <p className="text-center text-xs text-muted-foreground">{many ? 'Each guest shows their own code at the door. Share this ticket’s link with the person it’s for.' : 'Show this at the door to check in.'}</p>
                    </div>
                </div>

                <div className="mt-6 flex flex-wrap items-center justify-center gap-3 print:hidden">
                    {!voided && (
                        <Button variant="outline" size="sm" onClick={() => window.print()}>
                            <Download className="size-4" /> Save / print
                        </Button>
                    )}
                    {!voided && ticket.event.google_url && ticket.event.ics_url && (
                        <AddToCalendar googleUrl={ticket.event.google_url} icsUrl={ticket.event.ics_url} />
                    )}
                    <Link href={`/${region()}/e/${ticket.event.slug}/`} className="text-sm text-muted-foreground underline underline-offset-4">View event</Link>
                </div>

                {/* Powered by */}
                <Link href={`/${region()}/`} className="mt-8 flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground" aria-label="DropRSVP home">
                    powered by <LogoMark className="size-4" /> <span className="font-semibold">Drop RSVP</span>
                </Link>
            </div>
        </>
    );
}
