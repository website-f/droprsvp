import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowUpRight, BadgeCheck, Rocket, Sparkles, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import { PaymentConfirm, PaymentRedirecting } from '@/components/payment-flow';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Props {
    event: { slug: string; title: string; status: string; boosted_until: string | null };
    boost: { price: number; days: number };
    platform_fee_percent: number;
    result?: 'paid' | 'processing' | null;
}

const BENEFITS = [
    { icon: TrendingUp, text: 'Top placement in event discovery and search results' },
    { icon: Sparkles, text: 'A “Promoted” badge that makes your event stand out' },
    { icon: BadgeCheck, text: 'Priority consideration for the homepage “Happening soon”' },
];

export default function Promote({ event, boost, platform_fee_percent, result }: Props) {
    const form = useForm({});
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [redirecting, setRedirecting] = useState(false);
    const boosted = !!event.boosted_until;
    const until = event.boosted_until ? new Date(event.boosted_until).toLocaleDateString(undefined, { dateStyle: 'medium' }) : null;

    const proceed = () => {
        setConfirmOpen(false);
        setRedirecting(true);
        form.post(`/host/events/${event.slug}/promote`, { onFinish: () => setRedirecting(false) });
    };

    return (
        <>
            <Head title={`Promote · ${event.title}`} />
            <PaymentRedirecting show={redirecting} />
            <PaymentConfirm
                open={confirmOpen} onOpenChange={setConfirmOpen}
                heading={`Boost “${event.title}”`} lines={[{ label: 'Event boost', value: `${boost.days} days` }]} total={boost.price}
                note="You’ll be handed to our secure payment provider to complete the payment."
                processing={form.processing || redirecting} onProceed={proceed} ctaLabel="Proceed to payment"
            />
            <div className="mx-auto w-full max-w-2xl flex-1 p-4">
                {result === 'paid' && <div className="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm font-medium text-emerald-700 dark:text-emerald-400">🚀 Payment successful — your event is boosted!</div>}
                {result === 'processing' && <div className="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-500">Payment received — we’re activating your boost. Give it a moment and refresh if it isn’t showing yet.</div>}
                <div className="mb-6 flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/host/events" aria-label="Back"><ArrowLeft className="size-4" /></Link></Button>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Promote your event</h1>
                        <p className="text-sm text-muted-foreground">{event.title}</p>
                    </div>
                    {boosted && <Badge className="ml-auto gap-1 bg-[#6c63ff] text-white hover:bg-[#6c63ff]"><Rocket className="size-3.5" /> Boosted</Badge>}
                </div>

                {boosted && (
                    <div className="mb-4 rounded-xl border border-[#6c63ff]/30 bg-[#6c63ff]/10 p-4 text-sm">
                        🚀 This event is boosted until <strong>{until}</strong>. Paying again extends the boost.
                    </div>
                )}

                <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <div className="relative overflow-hidden bg-foreground p-6 text-background">
                        <div aria-hidden className="pointer-events-none absolute -right-10 -top-10 size-40 rounded-full opacity-40 blur-2xl" style={{ background: 'radial-gradient(circle,#6c63ff,transparent 70%)' }} />
                        <div className="relative">
                            <div className="flex items-center gap-2 text-sm font-medium opacity-80"><Rocket className="size-4" /> Event boost</div>
                            <div className="mt-2 text-4xl font-bold tracking-tight">RM {boost.price.toFixed(2)}</div>
                            <div className="text-sm opacity-80">for {boost.days} days of promotion</div>
                        </div>
                    </div>
                    <div className="p-6">
                        <ul className="grid gap-3">
                            {BENEFITS.map((b, i) => (
                                <li key={i} className="flex items-start gap-3 text-sm">
                                    <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-[#6c63ff]/10 text-[#6c63ff]"><b.icon className="size-4" /></span>
                                    {b.text}
                                </li>
                            ))}
                        </ul>
                        <Button type="button" size="lg" className="mt-6 w-full" onClick={() => setConfirmOpen(true)} disabled={form.processing || redirecting}>
                            <Rocket className="size-4" /> {boosted ? 'Extend boost' : 'Boost now'} · RM {boost.price.toFixed(2)}
                        </Button>
                        <p className="mt-3 text-center text-xs text-muted-foreground">Secure payment · powered by DropRSVP</p>
                    </div>
                </div>

                {/* Fee transparency */}
                <div className="mt-4 flex items-start gap-2 rounded-xl border border-border bg-muted/40 p-4 text-xs text-muted-foreground">
                    <ArrowUpRight className="mt-0.5 size-4 shrink-0" />
                    <span>Heads up: DropRSVP keeps a <strong className="text-foreground">{platform_fee_percent}%</strong> platform fee on your ticket sales. Boosting is a separate, optional promotion.</span>
                </div>
            </div>
        </>
    );
}

Promote.layout = {
    breadcrumbs: [{ title: 'Events', href: '/host/events' }, { title: 'Promote', href: '#' }],
};
