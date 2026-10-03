import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, AtSign, Coins, Gauge, Mail, Plus, ShieldCheck, Users } from 'lucide-react';
import { PALETTE } from '@/components/charts';
import { statusBadge } from '@/components/edm/campaign';
import { limitText, UsageMeters } from '@/components/edm/organizer-rules';
import type { Rules, UsageMap } from '@/components/edm/organizer-rules';
import { EdmHeader, HOST_EDM_CRUMB, pct, Section, Stat, StatusPill } from '@/components/edm/ui';
import { Button } from '@/components/ui/button';

interface Props {
    credits: { allowance: number; allowance_used: number; allowance_left: number; credits: number; available: number; premium: boolean; period: string };
    account: { suspended: boolean; reason: string | null };
    rates: { sent: number; bounced: number; unsubscribed: number; complaints: number; bounce_rate: number; unsubscribe_rate: number; complaint_rate: number };
    limits: { min_sent: number; bounce_rate: number; unsubscribe_rate: number; complaint_rate: number };
    subscribers: number;
    reachable: number;
    rules: Rules;
    usage: UsageMap;
    joined30: number;
    campaigns: { id: number; name: string; status: string; recipients: number; sent: number; open_rate: number | null; click_rate: number | null; when: string | null }[];
    domain: { domain: string; status: string } | null;
}

/** One guardrail as a meter: where they are against the limit that would suspend them. */
function Meter({ label, value, limit }: { label: string; value: number; limit: number }) {
    const ratio = Math.min(1, value / Math.max(limit, 0.0001));
    const tone = ratio >= 1 ? 'bg-rose-500' : ratio >= 0.6 ? 'bg-amber-500' : 'bg-emerald-500';

    return (
        <div className="grid gap-1">
            <div className="flex justify-between text-xs"><span>{label}</span><span className="tabular-nums text-muted-foreground">{(100 * value).toFixed(value < 0.01 ? 2 : 1)}% / {(100 * limit).toFixed(limit < 0.01 ? 1 : 0)}% limit</span></div>
            <div className="h-2 overflow-hidden rounded-full bg-muted"><div className={`h-full rounded-full ${tone}`} style={{ width: `${Math.max(2, ratio * 100)}%` }} /></div>
        </div>
    );
}

export default function HostEdmOverview({ credits, account, rates, limits, subscribers, reachable, rules, usage, joined30, campaigns, domain }: Props) {
    return (
        <>
            <Head title="Email marketing" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <EdmHeader
                    title="Email marketing"
                    description="Email the people who joined your events — ticket buyers who ticked “email me about future events” at your checkout."
                    actions={<Button onClick={() => router.visit('/host/edm/campaigns?new=1')} disabled={account.suspended}><Plus className="size-4" /> New campaign</Button>}
                />

                {account.suspended && (
                    <div className="mb-6 flex items-start gap-3 rounded-2xl border border-destructive/40 bg-destructive/5 p-4 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" />
                        <div><div className="font-semibold">Sending is suspended</div><div className="text-muted-foreground">{account.reason} A DropRSVP admin will review your account. Your campaigns are paused until then.</div></div>
                    </div>
                )}

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat
                        icon={Users}
                        label="People you can email"
                        value={reachable.toLocaleString()}
                        hint={reachable < subscribers ? `${subscribers.toLocaleString()} opted in · ${(subscribers - reachable).toLocaleString()} haven’t bought a ticket` : `+${joined30.toLocaleString()} opted in, last 30 days`}
                        tint={PALETTE[0]}
                    />
                    <Stat icon={Coins} label="Emails you can send" value={credits.available.toLocaleString()} hint={credits.allowance > 0 ? `${credits.allowance_left.toLocaleString()} free left in ${credits.period}` : 'From credits you bought'} tint={PALETTE[6]} />
                    <Stat icon={Mail} label="Sent, last 30 days" value={rates.sent.toLocaleString()} tint={PALETTE[2]} />
                    <Stat icon={AtSign} label="Sending from" value={domain?.status === 'verified' ? domain.domain : 'DropRSVP'} hint={domain && domain.status !== 'verified' ? `${domain.domain} not verified yet` : undefined} tint={PALETTE[4]} />
                </div>

                <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Section
                        title={<span className="flex items-center gap-2"><Coins className="size-4" /> Credits</span>}
                        description="One email, one credit. Free monthly emails are used first."
                        actions={<Button asChild size="sm" variant="outline"><Link href="/host/edm/credits">Buy credits</Link></Button>}
                    >
                        <div className="grid gap-3 text-sm">
                            {credits.allowance > 0 ? (
                                <div className="grid gap-1">
                                    <div className="flex justify-between text-xs"><span>Free this month ({credits.period})</span><span className="tabular-nums text-muted-foreground">{credits.allowance_used.toLocaleString()} / {credits.allowance.toLocaleString()} used</span></div>
                                    <div className="h-2 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${Math.round((100 * credits.allowance_used) / Math.max(1, credits.allowance))}%` }} /></div>
                                </div>
                            ) : (
                                <p className="text-xs text-muted-foreground">{credits.premium ? 'No free allowance on this account.' : <>Premium organizers get free emails every month. <Link href="/premium" className="font-medium text-foreground underline">Go Premium</Link></>}</p>
                            )}
                            <div className="flex justify-between border-t border-border pt-3"><span className="text-muted-foreground">Bought credits</span><span className="font-semibold tabular-nums">{credits.credits.toLocaleString()}</span></div>
                        </div>
                    </Section>

                    <Section
                        title={<span className="flex items-center gap-2"><ShieldCheck className="size-4" /> List health</span>}
                        description={rates.sent >= limits.min_sent
                            ? 'Last 30 days. Going past a limit suspends sending until we review it.'
                            : `Judged once ${limits.min_sent.toLocaleString()} emails have gone out in 30 days.`}
                    >
                        <div className="grid gap-3">
                            <Meter label="Bounces" value={rates.bounce_rate} limit={limits.bounce_rate} />
                            <Meter label="Unsubscribes" value={rates.unsubscribe_rate} limit={limits.unsubscribe_rate} />
                            <Meter label="Spam complaints" value={rates.complaint_rate} limit={limits.complaint_rate} />
                        </div>
                        <p className="mt-3 text-[11px] text-muted-foreground">Keep these low by emailing only people who opted in, recently, about things they came for.</p>
                    </Section>

                    <Section
                        title={<span className="flex items-center gap-2"><AtSign className="size-4" /> Your sending address</span>}
                        actions={<Button asChild size="sm" variant="outline"><Link href="/host/edm/domains">Manage</Link></Button>}
                    >
                        {domain ? (
                            <div className="flex items-center justify-between gap-2 text-sm">
                                <span className="truncate font-medium">{domain.domain}</span>
                                <StatusPill status={domain.status === 'verified' ? 'pass' : domain.status === 'failed' ? 'fail' : 'warn'} label={domain.status === 'verified' ? 'Verified' : domain.status === 'failed' ? 'Failing' : 'Pending'} />
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">Emails go out from DropRSVP’s address under your name. Add your own domain to send as you@yourdomain.com.</p>
                        )}
                    </Section>
                </div>

                <Section
                    className="mt-4"
                    title={<span className="flex items-center gap-2"><Gauge className="size-4" /> Your sending limits</span>}
                    description="Set by DropRSVP so every organizer’s email keeps reaching inboxes. Big campaigns are paced, not refused: whatever doesn’t fit this hour goes out in the next."
                >
                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                        <UsageMeters usage={usage} />
                        <dl className="grid grid-cols-2 gap-2 text-sm">
                            <div className="rounded-lg border border-border p-2.5"><dt className="text-[11px] text-muted-foreground">Recipients per campaign</dt><dd className="font-semibold tabular-nums">{limitText(rules.max_recipients)}</dd></div>
                            <div className="rounded-lg border border-border p-2.5"><dt className="text-[11px] text-muted-foreground">Emails per person, per week</dt><dd className="font-semibold tabular-nums">{limitText(rules.per_person_per_week)}</dd></div>
                            <div className="col-span-2 rounded-lg border border-border p-2.5 text-[11px] text-muted-foreground">
                                Only people who bought a ticket (or registered) for one of your events and opted in can receive your emails.
                            </div>
                        </dl>
                    </div>
                </Section>

                <Section
                    className="mt-4"
                    title="Recent campaigns"
                    actions={<Link href="/host/edm/campaigns" className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">All campaigns <ArrowRight className="size-3.5" /></Link>}
                >
                    {campaigns.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 py-10 text-center text-sm text-muted-foreground">
                            <Mail className="size-6" />
                            {subscribers === 0
                                ? 'Nobody on your list yet. Buyers can opt in at your checkout — your list grows with every sale.'
                                : 'Nothing sent yet. Start from a template for a quick first email.'}
                            {subscribers > 0 && <Button asChild size="sm" variant="outline" className="mt-1"><Link href="/host/edm/templates">Browse templates</Link></Button>}
                        </div>
                    ) : (
                        <ul className="divide-y divide-border">
                            {campaigns.map((c) => (
                                <li key={c.id}>
                                    <Link href={`/host/edm/campaigns/${c.id}`} className="flex flex-col gap-1 py-3 hover:bg-muted/30 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2"><span className="truncate font-medium">{c.name}</span>{statusBadge(c.status)}</div>
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
        </>
    );
}

HostEdmOverview.layout = { breadcrumbs: [HOST_EDM_CRUMB, { title: 'Overview', href: '/host/edm' }] };
