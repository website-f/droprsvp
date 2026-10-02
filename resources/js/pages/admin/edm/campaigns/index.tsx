import { Head, Link, router } from '@inertiajs/react';
import { Gauge, Mail, MailOpen, MousePointerClick, Plus, Search, Users } from 'lucide-react';
import { useState } from 'react';
import { PALETTE } from '@/components/charts';
import { statusBadge } from '@/components/edm/campaign';
import type { CampaignSummary, ThrottleStatus } from '@/components/edm/campaign';
import { NewCampaignDialog } from '@/components/edm/new-campaign-dialog';
import type { StarterChoice, TemplateChoice } from '@/components/edm/new-campaign-dialog';
import { EDM_CRUMB, EdmHeader, Pager, pct, Stat } from '@/components/edm/ui';
import { ResponsiveTable } from '@/components/responsive-table';
import { Button } from '@/components/ui/button';

interface Paginated { data: CampaignSummary[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number }

interface Props {
    campaigns: Paginated;
    stats: { subscribers: number; sent_30d: number; open_rate_30d: number | null; click_rate_30d: number | null };
    throttle: ThrottleStatus;
    repermissionEligible: number;
    filters: { status: string; q: string };
    statusCounts: Record<string, number>;
    starters: StarterChoice[];
    templates: TemplateChoice[];
    base?: string;
}

const TABS: { key: string; label: string; statuses: string[] }[] = [
    { key: '', label: 'All', statuses: [] },
    { key: 'draft', label: 'Drafts', statuses: ['draft'] },
    { key: 'scheduled', label: 'Scheduled', statuses: ['scheduled'] },
    { key: 'active', label: 'Sending', statuses: ['sending', 'paused'] },
    { key: 'sent', label: 'Sent', statuses: ['sent', 'cancelled'] },
];

export default function EdmCampaigns({ base = '/admin/edm', campaigns, stats, throttle, repermissionEligible, filters, statusCounts, starters, templates }: Props) {
    // ?new=1 (from the overview's button) opens the dialog straight away.
    const [creating, setCreating] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('new') === '1');
    const [q, setQ] = useState(filters.q);

    const go = (patch: Partial<Props['filters']>) => {
        const next = { ...filters, q, ...patch };
        router.get(`${base}/campaigns`, Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true });
    };

    const total = (statuses: string[]) => statuses.length === 0
        ? Object.values(statusCounts).reduce((a, b) => a + Number(b), 0)
        : statuses.reduce((a, s) => a + Number(statusCounts[s] ?? 0), 0);

    return (
        <>
            <Head title="Campaigns" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <EdmHeader
                    title="Campaigns"
                    description={base.startsWith("/host") ? "Emails to people who opted in to hear from you at checkout." : "Emails to people who opted in to hear from DropRSVP."}
                    actions={<Button onClick={() => setCreating(true)}><Plus className="size-4" /> New campaign</Button>}
                />

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat icon={Users} label="Subscribers" value={stats.subscribers.toLocaleString()} tint={PALETTE[0]} />
                    <Stat icon={Mail} label="Sent, last 30 days" value={stats.sent_30d.toLocaleString()} tint={PALETTE[2]} />
                    <Stat icon={MailOpen} label="Open rate, 30 days" value={pct(stats.open_rate_30d)} hint="A lower bound: many inboxes block the pixel." tint={PALETTE[1]} />
                    <Stat icon={MousePointerClick} label="Click rate, 30 days" value={pct(stats.click_rate_30d)} tint={PALETTE[6]} />
                </div>

                {repermissionEligible > 0 && (
                    <div className="mt-4 flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0 text-sm">
                            <div className="font-medium">
                                {repermissionEligible.toLocaleString()} {repermissionEligible === 1 ? 'person has' : 'people have'} an account or bought a ticket but {repermissionEligible === 1 ? 'was' : 'were'} never asked
                            </div>
                            <div className="text-xs text-muted-foreground">Send them one email asking whether they would like to hear from you. Only those who say yes join the list, and nobody is ever asked twice.</div>
                        </div>
                        <Button variant="outline" className="shrink-0" onClick={() => router.post(`${base}/campaigns`, { name: 'May we keep you posted?', kind: 'repermission' })}>
                            <Mail className="size-4" /> Prepare re-permission email
                        </Button>
                    </div>
                )}

                <section className="mt-6 rounded-2xl border border-border bg-card shadow-sm">
                    <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center lg:justify-between">
                        <div className="-mx-1 flex gap-1 overflow-x-auto px-1 [scrollbar-width:none]">
                            {TABS.map((t) => (
                                <button
                                    key={t.key}
                                    type="button"
                                    onClick={() => go({ status: t.key })}
                                    className={`flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${filters.status === t.key ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                                >
                                    {t.label}
                                    <span className={`rounded-full px-1.5 text-[11px] tabular-nums ${filters.status === t.key ? 'bg-background/20' : 'bg-muted'}`}>{total(t.statuses)}</span>
                                </button>
                            ))}
                        </div>
                        <form onSubmit={(e) => {
                            e.preventDefault();
                            go({ q });
                        }} className="relative">
                            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search campaigns…" className="h-9 w-full rounded-lg border border-input bg-card pl-8 pr-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20 lg:w-64" />
                        </form>
                    </div>

                    <ResponsiveTable>
                        <table className="w-full min-w-[760px] text-sm">
                            <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="px-4 py-3 font-medium">Campaign</th>
                                    <th className="px-4 py-3 font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Sent</th>
                                    <th className="px-4 py-3 text-right font-medium">Opened</th>
                                    <th className="px-4 py-3 text-right font-medium">Clicked</th>
                                    <th className="px-4 py-3 text-right font-medium">Bounced</th>
                                    <th className="px-4 py-3 text-right font-medium">Unsubscribed</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {campaigns.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-14 text-center">
                                            <div className="mx-auto flex max-w-sm flex-col items-center gap-2 text-sm text-muted-foreground">
                                                <Mail className="size-6" />
                                                {filters.status || filters.q ? 'No campaigns match.' : 'No campaigns yet. Start from a template for a quick, good-looking first email.'}
                                                {!filters.status && !filters.q && <Button size="sm" className="mt-1" onClick={() => setCreating(true)}><Plus className="size-4" /> New campaign</Button>}
                                            </div>
                                        </td>
                                    </tr>
                                ) : campaigns.data.map((c) => (
                                    <tr key={c.id} className="cursor-pointer hover:bg-muted/30" onClick={() => router.visit(`${base}/campaigns/${c.id}`)}>
                                        <td className="px-4 py-3">
                                            <Link href={`${base}/campaigns/${c.id}`} className="font-medium hover:underline">{c.name}</Link>
                                            <div className="text-xs text-muted-foreground">
                                                {c.kind === 'repermission' && 'Re-permission · '}
                                                {c.status === 'scheduled' && c.scheduled_at ? `Sends ${c.scheduled_at}` : c.started_at ? `Started ${c.started_at}` : `Edited ${c.updated_at}`}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">{statusBadge(c.status)}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{c.recipients ? `${c.sent.toLocaleString()} / ${c.recipients.toLocaleString()}` : '—'}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{c.sent ? pct(c.open_rate) : '—'}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{c.sent ? pct(c.click_rate) : '—'}</td>
                                        <td className={`px-4 py-3 text-right tabular-nums ${c.sent && c.bounced / c.sent > 0.02 ? 'text-rose-600 dark:text-rose-400' : ''}`}>{c.sent ? c.bounced.toLocaleString() : '—'}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{c.sent ? c.unsubscribed.toLocaleString() : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </ResponsiveTable>

                    <Pager prev={campaigns.prev_page_url} next={campaigns.next_page_url} page={campaigns.current_page} last={campaigns.last_page} />
                </section>

                <div className="mt-4 flex flex-wrap items-center gap-x-6 gap-y-1 rounded-2xl border border-border bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1.5 font-medium text-foreground"><Gauge className="size-3.5" /> Sending pace</span>
                    <span>{throttle.sent_last_hour} / {throttle.hourly_limit} this hour</span>
                    <span>up to {throttle.per_minute} a minute</span>
                    {throttle.warmup_daily_cap !== null && <span>warm-up: {throttle.sent_last_day} / {throttle.warmup_daily_cap} today</span>}
                </div>
            </div>

            <NewCampaignDialog open={creating} onOpenChange={setCreating} starters={starters} templates={templates} basePath={base} />
        </>
    );
}

EdmCampaigns.layout = { breadcrumbs: [EDM_CRUMB, { title: 'Campaigns', href: '/admin/edm/campaigns' }] };
