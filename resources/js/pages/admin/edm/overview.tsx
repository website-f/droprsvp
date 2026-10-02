import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Ban, Gauge, Mail, MailOpen, MousePointerClick, Plus, ShieldCheck, UserMinus, UserPlus, Users } from 'lucide-react';
import { AnalyticsToolbar } from '@/components/analytics-toolbar';
import type { AnalyticsPeriod } from '@/components/analytics-toolbar';
import { DonutChart, PALETTE, SeriesBars } from '@/components/charts';
import { statusBadge } from '@/components/edm/campaign';
import type { ThrottleStatus } from '@/components/edm/campaign';
import { EDM_CRUMB, EdmHeader, pct, Section, Stat, StatusPill } from '@/components/edm/ui';
import { Button } from '@/components/ui/button';

interface Props {
    kpis: {
        subscribers: number; joined: number; left: number; suppressed: number; sent: number;
        open_rate: number | null; click_rate: number | null; bounce_rate: number | null; unsub_rate: number | null; never_asked: number;
    };
    growth: { date: string; joined: number; left: number }[];
    volume: { date: string; sent: number; bounced: number }[];
    sources: { name: string; value: number }[];
    campaigns: { id: number; name: string; status: string; recipients: number; sent: number; open_rate: number | null; click_rate: number | null; when: string | null }[];
    throttle: ThrottleStatus;
    health: { pass: number; total: number; issues: { id: string; label: string; status: string; detail: string }[] };
    filters: AnalyticsPeriod & { periodLabel: string };
}

export default function EdmOverview({ kpis, growth, volume, sources, campaigns, throttle, health, filters }: Props) {
    const net = kpis.joined - kpis.left;
    const healthy = health.issues.length === 0;

    return (
        <>
            <Head title="EDM overview" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <EdmHeader
                    title="Email marketing"
                    description="Your list, what went out to it, and whether mail is reaching inboxes."
                    actions={(
                        <>
                            <AnalyticsToolbar path="/admin/edm" filters={filters} />
                            <Button onClick={() => router.visit('/admin/edm/campaigns?new=1')}><Plus className="size-4" /> New campaign</Button>
                        </>
                    )}
                />

                {/* Health first: nothing else matters if mail is not landing. */}
                <Link
                    href="/admin/edm/deliverability"
                    className={`mb-6 flex flex-col gap-3 rounded-2xl border p-4 transition-colors sm:flex-row sm:items-center sm:justify-between ${healthy ? 'border-emerald-500/30 bg-emerald-500/5 hover:bg-emerald-500/10' : 'border-amber-500/40 bg-amber-500/5 hover:bg-amber-500/10'}`}
                >
                    <div className="flex min-w-0 items-start gap-3">
                        <span className={`flex size-9 shrink-0 items-center justify-center rounded-xl ${healthy ? 'bg-emerald-500/15 text-emerald-600' : 'bg-amber-500/15 text-amber-600'}`}>
                            {healthy ? <ShieldCheck className="size-4" /> : <AlertTriangle className="size-4" />}
                        </span>
                        <div className="min-w-0">
                            <div className="text-sm font-semibold">Deliverability: {health.pass} of {health.total} checks passing</div>
                            <div className="text-xs text-muted-foreground">
                                {healthy ? 'Sending domain, server and bounce handling all look right.' : `Needs attention: ${health.issues.slice(0, 3).map((i) => i.label).join(', ')}${health.issues.length > 3 ? ` and ${health.issues.length - 3} more` : ''}.`}
                            </div>
                        </div>
                    </div>
                    <span className="inline-flex items-center gap-1 text-xs font-medium">Open checklist <ArrowRight className="size-3.5" /></span>
                </Link>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat icon={Users} label="Subscribers" value={kpis.subscribers.toLocaleString()} hint={`${net >= 0 ? '+' : ''}${net.toLocaleString()} net · ${filters.periodLabel}`} tint={PALETTE[0]} />
                    <Stat icon={Mail} label={`Emails sent · ${filters.periodLabel}`} value={kpis.sent.toLocaleString()} tint={PALETTE[2]} />
                    <Stat icon={MailOpen} label="Open rate" value={pct(kpis.open_rate)} hint="A lower bound — many inboxes block the pixel." tint={PALETTE[1]} />
                    <Stat icon={MousePointerClick} label="Click rate" value={pct(kpis.click_rate)} tint={PALETTE[6]} />
                    <Stat icon={UserPlus} label="Joined" value={kpis.joined.toLocaleString()} tint={PALETTE[4]} />
                    <Stat icon={UserMinus} label="Unsubscribed" value={kpis.left.toLocaleString()} hint={kpis.unsub_rate !== null ? `${pct(kpis.unsub_rate)} of emails sent` : undefined} tint={PALETTE[7]} />
                    <Stat icon={AlertTriangle} label="Bounce rate" value={pct(kpis.bounce_rate)} hint="Keep under 2%. Campaigns pause themselves at 5%." tint={PALETTE[3]} />
                    <Stat icon={Ban} label="Suppressed addresses" value={kpis.suppressed.toLocaleString()} hint="Bounced, complained or blocked — never mailed." tint={PALETTE[5]} />
                </div>

                {kpis.never_asked > 0 && (
                    <div className="mt-4 flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0 text-sm">
                            <div className="font-medium">{kpis.never_asked.toLocaleString()} {kpis.never_asked === 1 ? 'person has' : 'people have'} an account or a ticket but were never asked</div>
                            <div className="text-xs text-muted-foreground">One re-permission email asks whether they want to hear from you. Only those who say yes join; nobody is asked twice.</div>
                        </div>
                        <Button variant="outline" className="shrink-0" onClick={() => router.post('/admin/edm/campaigns', { name: 'May we keep you posted?', kind: 'repermission' })}>
                            <Mail className="size-4" /> Prepare re-permission email
                        </Button>
                    </div>
                )}

                <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Section title={`List growth · ${filters.periodLabel}`} description="People who opted in vs unsubscribed, per day.">
                        <SeriesBars data={growth} series={[{ key: 'joined', name: 'Joined', color: PALETTE[6] }, { key: 'left', name: 'Unsubscribed', color: PALETTE[3] }]} />
                    </Section>
                    <Section title={`Sending · ${filters.periodLabel}`} description="Campaign emails sent, and bounces read back.">
                        <SeriesBars data={volume} series={[{ key: 'sent', name: 'Sent', color: PALETTE[0] }, { key: 'bounced', name: 'Bounced', color: PALETTE[3] }]} />
                    </Section>
                </div>

                <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Section title="Where subscribers came from">
                        <DonutChart data={sources} />
                    </Section>

                    <Section
                        className="lg:col-span-2"
                        title="Recent campaigns"
                        actions={<Link href="/admin/edm/campaigns" className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">All campaigns <ArrowRight className="size-3.5" /></Link>}
                    >
                        {campaigns.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 py-10 text-center text-sm text-muted-foreground">
                                <Mail className="size-6" />
                                Nothing sent yet. Start from a template — it is the quickest way to a good first email.
                                <Button asChild variant="outline" size="sm" className="mt-1"><Link href="/admin/edm/templates">Browse templates</Link></Button>
                            </div>
                        ) : (
                            <ul className="divide-y divide-border">
                                {campaigns.map((c) => (
                                    <li key={c.id}>
                                        <Link href={`/admin/edm/campaigns/${c.id}`} className="flex flex-col gap-1 py-3 hover:bg-muted/30 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="truncate font-medium">{c.name}</span>
                                                    {statusBadge(c.status)}
                                                </div>
                                                <div className="text-xs text-muted-foreground">{c.when}</div>
                                            </div>
                                            <div className="flex shrink-0 gap-4 text-xs tabular-nums text-muted-foreground">
                                                <span><span className="font-semibold text-foreground">{c.sent.toLocaleString()}</span>/{c.recipients.toLocaleString()} sent</span>
                                                <span>Open <span className="font-semibold text-foreground">{pct(c.open_rate)}</span></span>
                                                <span>Click <span className="font-semibold text-foreground">{pct(c.click_rate)}</span></span>
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                </div>

                <div className="mt-4 flex flex-wrap items-center gap-x-6 gap-y-1 rounded-2xl border border-border bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1.5 font-medium text-foreground"><Gauge className="size-3.5" /> Sending pace</span>
                    <span>{throttle.sent_last_hour} / {throttle.hourly_limit} this hour</span>
                    <span>up to {throttle.per_minute} a minute</span>
                    {throttle.warmup_daily_cap !== null ? <span>warm-up: {throttle.sent_last_day} / {throttle.warmup_daily_cap} today</span> : <StatusPill status="pass" label="Warm-up complete" />}
                    <Link href="/admin/edm/settings" className="ml-auto font-medium text-foreground hover:underline">Settings</Link>
                </div>
            </div>
        </>
    );
}

EdmOverview.layout = { breadcrumbs: [EDM_CRUMB, { title: 'Overview', href: '/admin/edm' }] };
