import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Gauge, Info } from 'lucide-react';
import type { ThrottleStatus } from '@/components/edm/campaign';
import { Button } from '@/components/ui/button';

interface Props {
    settings: { hourly_limit: number; from_name: string; reply_to: string; postal_address: string };
    throttle: ThrottleStatus;
    fromAddress: string;
    mailer: { transport: string | null; host: string | null };
    warmup: { enabled: boolean; start_per_day: number; double_every_days: number };
}

const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function EdmSettings({ settings, throttle, fromAddress, mailer, warmup }: Props) {
    const form = useForm({
        hourly_limit: settings.hourly_limit,
        from_name: settings.from_name,
        reply_to: settings.reply_to,
        postal_address: settings.postal_address,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/edm/settings', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Email marketing settings" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <Link href="/admin/edm/campaigns" className="mb-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" /> Campaigns</Link>
                <h1 className="mb-1 text-2xl font-bold tracking-tight">Email marketing settings</h1>
                <p className="mb-6 text-sm text-muted-foreground">How campaign mail leaves the server.</p>

                <form onSubmit={submit} className="grid gap-5">
                    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-1 flex items-center gap-2 font-semibold"><Gauge className="size-4" /> Sending limit</h2>
                        <p className="mb-4 text-sm text-muted-foreground">
                            Your host caps outgoing email per hour, and tickets and receipts share that cap. Set this to about <strong>70% of the host’s limit</strong> so a campaign can never hold up a buyer’s ticket. Ask your host for the number, or find it in WHM under “Max hourly emails per domain”.
                        </p>
                        <label className="grid max-w-xs gap-1.5 text-sm">
                            <span className="font-medium">Campaign emails per hour</span>
                            <input type="number" min={1} className={field} value={form.data.hourly_limit} onChange={(e) => form.setData('hourly_limit', Number(e.target.value))} />
                            {form.errors.hourly_limit && <span className="text-xs text-destructive">{form.errors.hourly_limit}</span>}
                        </label>
                        <dl className="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            <div className="rounded-lg border border-border p-3"><dt className="text-xs text-muted-foreground">This hour</dt><dd className="font-semibold tabular-nums">{throttle.sent_last_hour} / {throttle.hourly_limit}</dd></div>
                            <div className="rounded-lg border border-border p-3"><dt className="text-xs text-muted-foreground">Per minute</dt><dd className="font-semibold tabular-nums">{throttle.per_minute}</dd></div>
                            <div className="rounded-lg border border-border p-3"><dt className="text-xs text-muted-foreground">Last 24 hours</dt><dd className="font-semibold tabular-nums">{throttle.sent_last_day}</dd></div>
                            <div className="rounded-lg border border-border p-3"><dt className="text-xs text-muted-foreground">Warm-up cap today</dt><dd className="font-semibold tabular-nums">{throttle.warmup_daily_cap ?? 'Done'}</dd></div>
                        </dl>
                        {warmup.enabled && (
                            <p className="mt-3 flex items-start gap-1.5 text-xs text-muted-foreground">
                                <Info className="mt-0.5 size-3.5 shrink-0" />
                                Warm-up: a new sending address starts at {warmup.start_per_day} emails a day and doubles every {warmup.double_every_days} days from the first campaign email, so mailbox providers learn to trust it. It switches itself off once it no longer limits anything.
                            </p>
                        )}
                    </section>

                    <section className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <h2 className="mb-4 font-semibold">Sender</h2>
                        <div className="grid gap-3">
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">From name</span>
                                <input className={field} value={form.data.from_name} onChange={(e) => form.setData('from_name', e.target.value)} />
                                <span className="text-xs text-muted-foreground">Default for new campaigns. Sends from <span className="font-medium text-foreground">{fromAddress}</span> (set in .env as EDM_FROM_ADDRESS).</span>
                            </label>
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Reply-to <span className="font-normal text-muted-foreground">(optional)</span></span>
                                <input type="email" className={field} value={form.data.reply_to} onChange={(e) => form.setData('reply_to', e.target.value)} placeholder="contact@droprsvp.com" />
                                {form.errors.reply_to && <span className="text-xs text-destructive">{form.errors.reply_to}</span>}
                            </label>
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Postal address</span>
                                <input className={field} value={form.data.postal_address} onChange={(e) => form.setData('postal_address', e.target.value)} placeholder="Company name, street, postcode, city" />
                                <span className="text-xs text-muted-foreground">Shown in every email’s footer. Commercial email is expected to carry one, and its absence counts against you with spam filters.</span>
                            </label>
                        </div>
                    </section>

                    <section className="rounded-2xl border border-border bg-muted/30 p-5 text-sm">
                        <h2 className="mb-1 font-semibold">Mail server</h2>
                        <p className="text-muted-foreground">
                            Transport <span className="font-medium text-foreground">{mailer.transport ?? '—'}</span>
                            {mailer.host && <> via <span className="font-medium text-foreground">{mailer.host}</span></>}. Set with the EDM_MAIL_* variables in .env; they fall back to the main MAIL_* settings.
                        </p>
                    </section>

                    <div><Button type="submit" disabled={form.processing || !form.isDirty}>{form.processing ? 'Saving…' : 'Save settings'}</Button></div>
                </form>
            </div>
        </>
    );
}

EdmSettings.layout = { breadcrumbs: [{ title: 'Email marketing', href: '/admin/edm/campaigns' }, { title: 'Settings', href: '/admin/edm/settings' }] };
