import { Head, Link, router, useForm } from '@inertiajs/react';
import { Gauge, Mail, MailOpen, MousePointerClick, Plus, Settings2, Users } from 'lucide-react';
import { useState } from 'react';
import { statusBadge } from '@/components/edm/campaign';
import type { CampaignSummary, ThrottleStatus } from '@/components/edm/campaign';
import { ResponsiveTable } from '@/components/responsive-table';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface Paginated { data: CampaignSummary[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number }

interface Props {
    campaigns: Paginated;
    stats: { subscribers: number; sent_30d: number; open_rate_30d: number | null; click_rate_30d: number | null };
    throttle: ThrottleStatus;
    repermissionEligible: number;
}

const pct = (n: number | null) => (n === null ? '—' : `${n}%`);

function Stat({ icon: Icon, label, value, hint, tint }: { icon: typeof Mail; label: string; value: string; hint?: string; tint: string }) {
    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
            <span className="flex size-9 items-center justify-center rounded-xl" style={{ backgroundColor: `${tint}1f`, color: tint }}><Icon className="size-4" /></span>
            <div className="mt-3 text-2xl font-bold tabular-nums tracking-tight">{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
            {hint && <div className="mt-1 text-[11px] text-muted-foreground">{hint}</div>}
        </div>
    );
}

export default function EdmCampaigns({ campaigns, stats, throttle, repermissionEligible }: Props) {
    const [creating, setCreating] = useState(false);
    const form = useForm({ name: '' });

    const create = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/edm/campaigns');
    };

    return (
        <>
            <Head title="Email marketing" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Email marketing</h1>
                        <p className="text-sm text-muted-foreground">Campaigns to people who opted in to hear from DropRSVP.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline"><Link href="/admin/edm/subscribers"><Users className="size-4" /> Subscribers</Link></Button>
                        <Button asChild variant="outline"><Link href="/admin/edm/settings"><Settings2 className="size-4" /> Settings</Link></Button>
                        <Button onClick={() => setCreating(true)}><Plus className="size-4" /> New campaign</Button>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat icon={Users} label="Subscribers" value={stats.subscribers.toLocaleString()} tint="#6c63ff" />
                    <Stat icon={Mail} label="Sent, last 30 days" value={stats.sent_30d.toLocaleString()} tint="#2ec4b6" />
                    <Stat icon={MailOpen} label="Open rate, 30 days" value={pct(stats.open_rate_30d)} hint="A lower bound: many inboxes block the open pixel." tint="#f5a524" />
                    <Stat icon={MousePointerClick} label="Click rate, 30 days" value={pct(stats.click_rate_30d)} tint="#22c55e" />
                </div>

                {/* Existing users never opted in, so the list starts small. This
                    is the one sanctioned way to grow it from people we already know. */}
                {repermissionEligible > 0 && (
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4">
                        <div className="min-w-0 text-sm">
                            <div className="font-medium">
                                {repermissionEligible.toLocaleString()} {repermissionEligible === 1 ? 'person has' : 'people have'} an account or bought a ticket but {repermissionEligible === 1 ? 'was' : 'were'} never asked
                            </div>
                            <div className="text-xs text-muted-foreground">Send them one email asking whether they would like to hear from you. Only those who say yes join the list, and nobody is ever asked twice.</div>
                        </div>
                        <Button
                            variant="outline"
                            onClick={() => router.post('/admin/edm/campaigns', { name: 'May we keep you posted?', kind: 'repermission' })}
                        >
                            <Mail className="size-4" /> Prepare re-permission email
                        </Button>
                    </div>
                )}

                {/* Why sending is slow is the first question anyone asks. */}
                <div className="mt-4 flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl border border-border bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1.5 font-medium text-foreground"><Gauge className="size-3.5" /> Sending pace</span>
                    <span>{throttle.sent_last_hour} / {throttle.hourly_limit} this hour</span>
                    <span>up to {throttle.per_minute} a minute</span>
                    {throttle.warmup_daily_cap !== null && <span>warm-up: {throttle.sent_last_day} / {throttle.warmup_daily_cap} today</span>}
                </div>

                <ResponsiveTable className="mt-6 rounded-2xl border border-border">
                    <table className="w-full min-w-[760px] text-sm">
                        <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">Campaign</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 text-right font-medium">Sent</th>
                                <th className="px-4 py-3 text-right font-medium">Opened</th>
                                <th className="px-4 py-3 text-right font-medium">Clicked</th>
                                <th className="px-4 py-3 text-right font-medium">Unsubscribed</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {campaigns.data.length === 0 ? (
                                <tr><td colSpan={6} className="px-4 py-12 text-center text-muted-foreground">No campaigns yet. Start with “New campaign”.</td></tr>
                            ) : campaigns.data.map((c) => (
                                <tr key={c.id} className="cursor-pointer hover:bg-muted/30" onClick={() => router.visit(`/admin/edm/campaigns/${c.id}`)}>
                                    <td className="px-4 py-3">
                                        <Link href={`/admin/edm/campaigns/${c.id}`} className="font-medium hover:underline">{c.name}</Link>
                                        <div className="text-xs text-muted-foreground">
                                            {c.status === 'scheduled' && c.scheduled_at ? `Sends ${c.scheduled_at}` : c.started_at ? `Started ${c.started_at}` : `Edited ${c.updated_at}`}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">{statusBadge(c.status)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{c.recipients ? `${c.sent.toLocaleString()} / ${c.recipients.toLocaleString()}` : '—'}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{c.sent ? pct(c.open_rate) : '—'}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{c.sent ? pct(c.click_rate) : '—'}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{c.sent ? c.unsubscribed.toLocaleString() : '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </ResponsiveTable>

                {(campaigns.prev_page_url || campaigns.next_page_url) && (
                    <div className="mt-6 flex items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">Page {campaigns.current_page} of {campaigns.last_page}</span>
                        <div className="flex gap-2">
                            <Button asChild variant="outline" size="sm" disabled={!campaigns.prev_page_url}>{campaigns.prev_page_url ? <Link href={campaigns.prev_page_url} preserveScroll>← Prev</Link> : <span>← Prev</span>}</Button>
                            <Button asChild variant="outline" size="sm" disabled={!campaigns.next_page_url}>{campaigns.next_page_url ? <Link href={campaigns.next_page_url} preserveScroll>Next →</Link> : <span>Next →</span>}</Button>
                        </div>
                    </div>
                )}
            </div>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <DialogHeader><DialogTitle>New campaign</DialogTitle></DialogHeader>
                    <form onSubmit={create} className="grid gap-3">
                        <label className="grid gap-1.5 text-sm">
                            <span className="font-medium">Name</span>
                            <input
                                autoFocus
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. October line-up"
                                className="h-10 rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                            />
                            <span className="text-xs text-muted-foreground">Only you see this. The subject line comes next.</span>
                            {form.errors.name && <span className="text-xs text-destructive">{form.errors.name}</span>}
                        </label>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing || !form.data.name.trim()}>Create</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

EdmCampaigns.layout = { breadcrumbs: [{ title: 'Email marketing', href: '/admin/edm/campaigns' }] };
