import { CalendarClock, CheckCircle2, Copy, Mail, MapPin, MessageCircle, MessageSquareText, Phone, Receipt, RotateCcw, Sparkles, Tag, Ticket, UserRound } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export interface AttendeeRow {
    id: number; token: string; name: string; email: string | null; phone: string | null;
    type: string | null; seat: string | null; order_ref: string | null; purchased_at: string | null;
    status: string; checked_in_at: string | null;
    answers?: Record<string, string>;
    questions?: Array<{ label: string; answer: string | null }>;
    buyer?: {
        gender: string | null; age_band: string | null; birth_year: number | null; age: number | null;
        city: string | null; state: string | null; source: string | null; notes: string | null;
    };
    order?: { tickets: number; total: number | null; discount: number | null; code: string | null; currency: string };
}

const STATUS_BADGE: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    checked_in: 'default', valid: 'secondary', void: 'destructive', refunded: 'destructive', cancelled: 'destructive',
};
const STATUS_LABEL: Record<string, string> = {
    checked_in: 'Checked in', valid: 'Not checked in', void: 'Void', refunded: 'Refunded', cancelled: 'Cancelled',
};

/**
 * Malaysian numbers are typed "012-345 6789", "+60 12…", "60123…". wa.me wants
 * the international form with no punctuation, so normalise to that — a local
 * leading 0 becomes the 60 country code.
 */
function whatsappUrl(phone: string): string | null {
    const digits = phone.replace(/\D+/g, '');

    if (digits.length < 8) {
        return null;
    }

    const international = digits.startsWith('0') ? `6${digits}` : digits;

    return `https://wa.me/${international}`;
}

function initials(name: string): string {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
}

/**
 * Everything checkout learned about one ticket-holder, on one screen.
 *
 * The old detail view showed contact details and the ticket — not the booking
 * questions the organizer had asked (which were already in the row, just never
 * rendered), and none of the profile checkout collects. An organizer who asked
 * "Have you played before?" had to export a CSV to find out.
 *
 * Laid out as the organizer reads it: who this is and how to reach them, what
 * they told you, then the paperwork.
 */
export function AttendeeProfile({
    row,
    working,
    onClose,
    onCheckIn,
    onUndo,
}: {
    row: AttendeeRow | null;
    working: boolean;
    onClose: () => void;
    onCheckIn: (id: number) => void;
    onUndo: (id: number, name: string) => void;
}) {
    const buyer = row?.buyer;
    const order = row?.order;
    const questions = row?.questions ?? [];
    const wa = row?.phone ? whatsappUrl(row.phone) : null;

    const copy = async (text: string, what: string) => {
        try {
            await navigator.clipboard.writeText(text);
            toast.success(`${what} copied`);
        } catch {
            toast.error(`Couldn't copy the ${what.toLowerCase()}`);
        }
    };

    const money = (n: number | null | undefined) => `${order?.currency ?? 'MYR'} ${(n ?? 0).toFixed(2)}`;

    // The profile line under the name: "Female · 27 · Kajang, Selangor".
    const summary = [
        buyer?.gender,
        buyer?.age != null ? `${buyer.age}` : buyer?.age_band,
        buyer?.city ? [buyer.city, buyer.state && buyer.state !== buyer.city ? buyer.state : null].filter(Boolean).join(', ') : null,
    ].filter(Boolean).join(' · ');

    return (
        <Dialog open={!!row} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] gap-0 overflow-y-auto p-0 sm:max-w-lg">
                {row && (
                    <>
                        {/* Header */}
                        <DialogHeader className="space-y-0 border-b border-border p-5 text-left">
                            <div className="flex items-start gap-3">
                                <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-foreground text-base font-bold text-background">
                                    {initials(row.name)}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <DialogTitle className="break-words text-lg">{row.name}</DialogTitle>
                                    <DialogDescription className="mt-0.5">
                                        {summary || 'No profile details given'}
                                    </DialogDescription>
                                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                        <Badge variant={STATUS_BADGE[row.status] ?? 'secondary'}>{STATUS_LABEL[row.status] ?? row.status}</Badge>
                                        {row.type && <Badge variant="outline"><Ticket className="size-3" /> {row.type}</Badge>}
                                        {row.seat && <Badge variant="outline">{row.seat}</Badge>}
                                    </div>
                                </div>
                            </div>

                            {/* The one action that matters at the door. */}
                            <div className="mt-4 flex gap-2">
                                {row.status === 'valid' && (
                                    <Button className="flex-1" disabled={working} onClick={() => onCheckIn(row.id)}>
                                        <CheckCircle2 className="size-4" /> Check in
                                    </Button>
                                )}
                                {row.status === 'checked_in' && (
                                    <Button variant="outline" className="flex-1" disabled={working} onClick={() => onUndo(row.id, row.name)}>
                                        <RotateCcw className="size-4" /> Undo check-in
                                    </Button>
                                )}
                            </div>
                            {row.checked_in_at && (
                                <p className="mt-2 text-xs text-muted-foreground">Arrived {row.checked_in_at}</p>
                            )}
                        </DialogHeader>

                        <div className="grid gap-5 p-5">
                            {/* Reach them */}
                            <Section icon={<UserRound className="size-3.5" />} title="Contact">
                                <div className="grid gap-2">
                                    {row.email ? (
                                        <ContactLine icon={<Mail className="size-4" />} value={row.email} href={`mailto:${row.email}`} onCopy={() => copy(row.email!, 'Email')} />
                                    ) : <Missing label="No email" />}
                                    {row.phone ? (
                                        <ContactLine
                                            icon={<Phone className="size-4" />}
                                            value={row.phone}
                                            href={`tel:${row.phone}`}
                                            onCopy={() => copy(row.phone!, 'Phone number')}
                                            extra={wa && (
                                                <a href={wa} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 rounded-full bg-emerald-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-emerald-700">
                                                    <MessageCircle className="size-3" /> WhatsApp
                                                </a>
                                            )}
                                        />
                                    ) : <Missing label="No phone number" />}
                                </div>
                            </Section>

                            {/* What they told you */}
                            {questions.length > 0 && (
                                <Section icon={<Sparkles className="size-3.5" />} title="Booking questions">
                                    <dl className="grid gap-2">
                                        {questions.map((q) => (
                                            <div key={q.label} className="rounded-lg border border-border px-3 py-2">
                                                <dt className="text-xs text-muted-foreground">{q.label}</dt>
                                                <dd className={`mt-0.5 break-words text-sm ${q.answer ? 'font-medium' : 'text-muted-foreground italic'}`}>
                                                    {q.answer ?? 'Not answered'}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </Section>
                            )}

                            {buyer?.notes && (
                                <Section icon={<MessageSquareText className="size-3.5" />} title="Remarks">
                                    {/* Called out: this is where access needs and allergies turn up. */}
                                    <p className="whitespace-pre-line break-words rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm">
                                        {buyer.notes}
                                    </p>
                                </Section>
                            )}

                            <Section icon={<MapPin className="size-3.5" />} title="Profile">
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                    <Detail label="Gender" value={buyer?.gender} />
                                    <Detail label="Age" value={buyer?.age != null ? `${buyer.age} (born ${buyer.birth_year})` : buyer?.age_band} />
                                    <Detail label="City" value={buyer?.city} />
                                    <Detail label="State" value={buyer?.state} />
                                    <Detail label="Heard via" value={buyer?.source} />
                                </dl>
                            </Section>

                            <Section icon={<Receipt className="size-3.5" />} title="Order">
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                    <Detail label="Reference" value={row.order_ref} mono />
                                    <Detail label="Purchased" value={row.purchased_at} />
                                    <Detail label="Paid" value={order?.total != null ? money(order.total) : null} />
                                    <Detail label="Tickets in order" value={order ? String(order.tickets) : null} />
                                    {order?.code && (
                                        <Detail
                                            label="Promo code"
                                            value={`${order.code}${order.discount ? ` (−${money(order.discount)})` : ''}`}
                                            icon={<Tag className="size-3" />}
                                        />
                                    )}
                                </dl>
                                {order && order.tickets > 1 && (
                                    <p className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <CalendarClock className="size-3.5" /> Bought together with {order.tickets - 1} other ticket{order.tickets === 2 ? '' : 's'} — search the reference to see the group.
                                    </p>
                                )}
                            </Section>

                            <div className="flex items-center justify-between gap-2 rounded-lg bg-muted/50 px-3 py-2 text-xs">
                                <span className="text-muted-foreground">Ticket code</span>
                                <button type="button" onClick={() => copy(row.token, 'Ticket code')} className="inline-flex min-w-0 items-center gap-1 font-mono hover:underline">
                                    <span className="truncate">{row.token}</span> <Copy className="size-3 shrink-0" />
                                </button>
                            </div>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function Section({ icon, title, children }: { icon: React.ReactNode; title: string; children: React.ReactNode }) {
    return (
        <section className="grid gap-2">
            <h3 className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{icon} {title}</h3>
            {children}
        </section>
    );
}

function ContactLine({ icon, value, href, onCopy, extra }: { icon: React.ReactNode; value: string; href: string; onCopy: () => void; extra?: React.ReactNode }) {
    return (
        <div className="flex items-center gap-2 rounded-lg border border-border px-3 py-2">
            <span className="shrink-0 text-muted-foreground">{icon}</span>
            <a href={href} className="min-w-0 flex-1 truncate text-sm font-medium hover:underline">{value}</a>
            {extra}
            <button type="button" onClick={onCopy} aria-label={`Copy ${value}`} className="shrink-0 rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground">
                <Copy className="size-3.5" />
            </button>
        </div>
    );
}

function Missing({ label }: { label: string }) {
    return <p className="rounded-lg border border-dashed border-border px-3 py-2 text-sm text-muted-foreground">{label}</p>;
}

function Detail({ label, value, mono, icon }: { label: string; value: string | null | undefined; mono?: boolean; icon?: React.ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className={`mt-0.5 flex items-center gap-1 break-words ${mono ? 'font-mono text-xs' : ''} ${value ? '' : 'text-muted-foreground'}`}>
                {icon}{value || '—'}
            </dd>
        </div>
    );
}
