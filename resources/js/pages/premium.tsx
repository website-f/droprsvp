import { Head, useForm, usePage } from '@inertiajs/react';
import { Check, Crown, MessageCircle, ShieldCheck, Users } from 'lucide-react';
import { useState } from 'react';
import { PaymentConfirm, PaymentRedirecting } from '@/components/payment-flow';
import { Button } from '@/components/ui/button';

interface Props { price: number; days: number; is_premium: boolean; premium_until: string | null; result?: 'paid' | 'processing' | null }

const BENEFITS = [
    { icon: Users, title: 'See everyone who’s going', text: 'Unlock the full member list on every event — not just the first four.' },
    { icon: MessageCircle, title: 'Join the conversation', text: 'Ask questions and reply in event discussions with organizers and other guests.' },
    { icon: Crown, title: 'Premium badge', text: 'A Crown badge shows on your account while your membership is active.' },
];

export default function Premium({ price, days, is_premium, premium_until, result }: Props) {
    const flash = usePage().props.flash as { success?: string } | undefined;
    const form = useForm({});
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [redirecting, setRedirecting] = useState(false);
    const until = premium_until ? new Date(premium_until).toLocaleDateString(undefined, { dateStyle: 'medium' }) : null;

    const proceed = () => {
        setConfirmOpen(false);
        setRedirecting(true);
        form.post('/premium/subscribe', { onFinish: () => setRedirecting(false) });
    };

    return (
        <>
            <Head title="DropRSVP Premium" />
            <PaymentRedirecting show={redirecting} />
            <PaymentConfirm
                open={confirmOpen} onOpenChange={setConfirmOpen}
                heading="DropRSVP Premium" lines={[{ label: 'Membership', value: `${days} days` }]} total={price}
                note="You’ll be handed to our secure payment provider to complete the payment."
                processing={form.processing || redirecting} onProceed={proceed}
            />
            <div className="mx-auto w-full max-w-2xl flex-1 p-4">
                {result === 'paid' && <div className="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm font-medium text-emerald-700 dark:text-emerald-400">🎉 Payment successful — welcome to Premium!</div>}
                {result === 'processing' && <div className="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-500">Payment received — we’re activating your Premium. Give it a moment and refresh if it isn’t showing yet.</div>}
                {flash?.success && <div className="mb-4 rounded-lg border border-foreground bg-foreground p-3 text-sm text-background">{flash.success}</div>}

                <div className="overflow-hidden rounded-3xl border border-border bg-card shadow-sm">
                    <div className="relative overflow-hidden bg-foreground p-8 text-background">
                        <div aria-hidden className="pointer-events-none absolute -right-12 -top-12 size-56 rounded-full opacity-40 blur-3xl" style={{ background: 'radial-gradient(circle,#f5a524,transparent 70%)' }} />
                        <div className="relative">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-background/15 px-3 py-1 text-xs font-semibold"><Crown className="size-3.5" /> DropRSVP Premium</span>
                            {is_premium ? (
                                <>
                                    <h1 className="mt-4 text-3xl font-bold tracking-tight">You’re Premium 🎉</h1>
                                    <p className="mt-1 text-sm opacity-80">Active until {until}.</p>
                                </>
                            ) : (
                                <>
                                    <h1 className="mt-4 text-3xl font-bold tracking-tight">Get the most out of every event</h1>
                                    <div className="mt-3 flex items-baseline gap-1">
                                        <span className="text-4xl font-bold">RM {price.toFixed(2)}</span>
                                        <span className="text-sm opacity-80">/ {days} days</span>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="p-8">
                        <ul className="grid gap-5">
                            {BENEFITS.map((b, i) => (
                                <li key={i} className="flex items-start gap-4">
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#f5a524]/15 text-[#f5a524]"><b.icon className="size-5" /></span>
                                    <div>
                                        <div className="flex items-center gap-2 font-semibold">{b.title} <Check className="size-4 text-emerald-500" /></div>
                                        <p className="mt-0.5 text-sm text-muted-foreground">{b.text}</p>
                                    </div>
                                </li>
                            ))}
                        </ul>

                        {is_premium ? (
                            <div className="mt-8 flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm">
                                <ShieldCheck className="size-4 text-emerald-600" /> All premium benefits are active on your account.
                            </div>
                        ) : (
                            <>
                                <Button type="button" size="lg" className="mt-8 w-full" onClick={() => setConfirmOpen(true)} disabled={form.processing || redirecting}>
                                    <Crown className="size-4" /> Go Premium · RM {price.toFixed(2)}
                                </Button>
                                <p className="mt-3 text-center text-xs text-muted-foreground">Secure payment · cancel anytime · powered by DropRSVP</p>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

Premium.layout = { breadcrumbs: [{ title: 'Premium', href: '/premium' }] };
