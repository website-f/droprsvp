import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, BookmarkPlus, CalendarClock, Copy, Eye, Loader2, Mail, MailOpen, MousePointerClick, Pause, Pencil, Play, Send, Trash2, UserMinus, Users, XCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { statusBadge } from '@/components/edm/campaign';
import type { CampaignSummary, ThrottleStatus } from '@/components/edm/campaign';
import { SpamCheckCard } from '@/components/edm/spam-check';
import type { SpamResult } from '@/components/edm/spam-check';
import { EDM_CRUMB } from '@/components/edm/ui';
import { ResponsiveTable } from '@/components/responsive-table';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SearchableSelect } from '@/components/ui/searchable-select';
import type { SearchableOption } from '@/components/ui/searchable-select';
import { postJson } from '@/lib/api';

interface AudienceSpec {
    cities: string[];
    event_ids: number[];
    category_ids: number[];
    purchased_within_days: number | null;
    purchase: 'any' | 'buyers' | 'non_buyers';
}

interface Campaign extends CampaignSummary {
    subject: string; preheader: string | null; from_name: string | null; reply_to: string | null;
    audience: AudienceSpec; has_content: boolean; paused_reason: string | null; scheduled_at_local: string | null;
}

interface Props {
    campaign: Campaign;
    audienceCount: number;
    /** Re-permission only: how many said yes. */
    confirmed: number | null;
    options: { cities: string[]; events: SearchableOption[]; categories: SearchableOption[] };
    links: { url: string; clicks: number; unique_clicks: number }[];
    throttle: ThrottleStatus;
    fromAddress: string;
    testEmail: string;
    /** Unsent campaigns only. */
    spam: SpamResult | null;
}

const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const card = 'rounded-2xl border border-border bg-card p-5 shadow-sm';
const pct = (n: number | null) => (n === null ? '—' : `${n}%`);

function Tile({ icon: Icon, label, value, sub }: { icon: typeof Mail; label: string; value: string; sub?: string }) {
    return (
        <div className="rounded-xl border border-border p-3">
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground"><Icon className="size-3.5" /> {label}</div>
            <div className="mt-1 text-xl font-bold tabular-nums">{value}</div>
            {sub && <div className="text-[11px] text-muted-foreground">{sub}</div>}
        </div>
    );
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-full border px-3 py-1 text-xs transition-colors ${active ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}
        >
            {children}
        </button>
    );
}

/** How long sending will take at the current pace — the first question asked. */
function estimate(recipients: number, t: ThrottleStatus): string {
    if (recipients <= 0) {
        return '';
    }

    const perHour = t.warmup_daily_cap !== null ? Math.min(t.hourly_limit, t.warmup_daily_cap / 24) : t.hourly_limit;
    const hours = recipients / Math.max(1, perHour);

    if (hours < 1) {
        return `about ${Math.max(1, Math.ceil(hours * 60))} minutes`;
    }

    return hours < 48 ? `about ${Math.ceil(hours)} hours` : `about ${Math.ceil(hours / 24)} days`;
}

export default function EdmCampaign({ campaign, audienceCount, confirmed, options, links, throttle, fromAddress, testEmail, spam }: Props) {
    const confirm = useConfirm();
    const editable = campaign.editable;
    const started = !!campaign.started_at;
    const repermission = campaign.kind === 'repermission';

    const form = useForm({
        name: campaign.name,
        subject: campaign.subject ?? '',
        preheader: campaign.preheader ?? '',
        from_name: campaign.from_name ?? '',
        reply_to: campaign.reply_to ?? '',
        audience: campaign.audience,
    });

    // Live count as the filters change, before anything is saved.
    const [count, setCount] = useState(audienceCount);
    const [counting, setCounting] = useState(false);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        setCounting(true);
        const id = window.setTimeout(() => {
            postJson<{ count: number }>('/admin/edm/audience-count', { audience: form.data.audience })
                .then((r) => setCount(r.count))
                .catch(() => {})
                .finally(() => setCounting(false));
        }, 350);

        return () => window.clearTimeout(id);
    }, [form.data.audience]);

    const setAudience = (patch: Partial<AudienceSpec>) => form.setData('audience', { ...form.data.audience, ...patch });
    const toggle = <T,>(list: T[], v: T) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);

    const save = (e?: React.FormEvent) => {
        e?.preventDefault();
        form.put(`/admin/edm/campaigns/${campaign.id}`, { preserveScroll: true });
    };

    const test = useForm({ emails: testEmail });
    const [savingTemplate, setSavingTemplate] = useState(false);
    const templateForm = useForm({ name: campaign.name, description: '', campaign_id: campaign.id });
    const schedule = useForm({ at: campaign.scheduled_at_local ?? '' });

    const act = (path: string) => router.post(`/admin/edm/campaigns/${campaign.id}/${path}`, {}, { preserveScroll: true });

    const sendNow = async () => {
        if (form.isDirty) {
            return void confirm({ title: 'Save your changes first', description: 'The subject or audience has unsaved edits. Save them, then send.', confirmText: 'OK' });
        }

        const ok = await confirm({
            title: `Send to ${count.toLocaleString()} ${count === 1 ? 'person' : 'people'}?`,
            description: `${spam?.level === 'poor' ? `The spam check scores this ${spam.score}/10 — a lot of it may land in spam. Consider fixing the issues first. ` : ''}Emails go out a few at a time within your hourly limit, ${estimate(count, throttle)} in total. You can pause at any point, but sent emails cannot be recalled.`,
            confirmText: spam?.level === 'poor' ? 'Send anyway' : 'Send campaign',
        });

        if (ok) {
            act('send');
        }
    };

    const remove = async () => {
        if (await confirm({ title: 'Delete this draft?', description: 'It has not been sent, so nothing else is affected.', confirmText: 'Delete', destructive: true })) {
            router.delete(`/admin/edm/campaigns/${campaign.id}`);
        }
    };

    const cancel = async () => {
        if (await confirm({ title: 'Cancel this campaign?', description: 'Nothing more will be sent. Emails already delivered stay delivered.', confirmText: 'Cancel campaign', destructive: true })) {
            act('cancel');
        }
    };

    const progress = campaign.recipients > 0 ? Math.round((100 * (campaign.sent + campaign.failed)) / campaign.recipients) : 0;

    return (
        <>
            <Head title={campaign.name} />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <Link href="/admin/edm/campaigns" className="mb-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" /> Campaigns</Link>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="truncate text-2xl font-bold tracking-tight">{campaign.name}</h1>
                            {statusBadge(campaign.status)}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {campaign.status === 'sending' && <Button variant="outline" onClick={() => act('pause')}><Pause className="size-4" /> Pause</Button>}
                        {campaign.status === 'paused' && <Button onClick={() => act('resume')}><Play className="size-4" /> Resume</Button>}
                        {['sending', 'paused'].includes(campaign.status) && <Button variant="outline" onClick={cancel}><XCircle className="size-4" /> Cancel</Button>}
                        <Button variant="outline" onClick={() => act('duplicate')}><Copy className="size-4" /> Duplicate</Button>
                        {!repermission && <Button variant="outline" onClick={() => setSavingTemplate(true)}><BookmarkPlus className="size-4" /> Save as template</Button>}
                        {editable && <Button variant="ghost" onClick={remove} aria-label="Delete draft"><Trash2 className="size-4" /></Button>}
                    </div>
                </div>

                {campaign.paused_reason && (
                    <div className="mb-5 flex items-start gap-2 rounded-xl border border-destructive/40 bg-destructive/5 p-4 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" />
                        <div><span className="font-medium">Paused.</span> {campaign.paused_reason}</div>
                    </div>
                )}

                {/* ---- report ------------------------------------------------ */}
                {started && (
                    <section className={`${card} mb-5`}>
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-semibold">Results</h2>
                            <span className="text-xs text-muted-foreground">Started {campaign.started_at}{campaign.finished_at ? ` · finished ${campaign.finished_at}` : ''}</span>
                        </div>

                        {campaign.status !== 'sent' && campaign.recipients > 0 && (
                            <div className="mb-4">
                                <div className="mb-1 flex justify-between text-xs text-muted-foreground">
                                    <span>{(campaign.sent + campaign.failed).toLocaleString()} of {campaign.recipients.toLocaleString()} processed</span>
                                    <span>{progress}%</span>
                                </div>
                                <div className="h-2 overflow-hidden rounded-full bg-muted"><div className="h-full bg-foreground transition-all" style={{ width: `${progress}%` }} /></div>
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                            <Tile icon={Users} label="Recipients" value={campaign.recipients.toLocaleString()} />
                            <Tile icon={Send} label="Sent" value={campaign.sent.toLocaleString()} sub={campaign.failed ? `${campaign.failed} failed` : undefined} />
                            <Tile icon={MailOpen} label="Opened" value={pct(campaign.open_rate)} sub={`${campaign.opened.toLocaleString()} people`} />
                            <Tile icon={MousePointerClick} label="Clicked" value={pct(campaign.click_rate)} sub={`${campaign.clicked.toLocaleString()} people`} />
                            {repermission && confirmed !== null
                                ? <Tile icon={Users} label="Said yes" value={confirmed.toLocaleString()} sub={campaign.sent ? `${Math.round((100 * confirmed) / campaign.sent)}% of sent` : undefined} />
                                : <Tile icon={UserMinus} label="Unsubscribed" value={campaign.unsubscribed.toLocaleString()} />}
                            <Tile icon={AlertTriangle} label="Bounced" value={campaign.bounced.toLocaleString()} />
                        </div>
                        <p className="mt-2 text-[11px] text-muted-foreground">Opens are a lower bound: many inboxes block the tracking pixel. A click also counts as an open.</p>

                        {links.length > 0 && (
                            <ResponsiveTable className="mt-4 rounded-xl border border-border">
                                <table className="w-full min-w-[560px] text-sm">
                                    <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                        <tr><th className="px-3 py-2 font-medium">Link</th><th className="px-3 py-2 text-right font-medium">People</th><th className="px-3 py-2 text-right font-medium">Clicks</th></tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {links.map((l) => (
                                            <tr key={l.url}>
                                                <td className="max-w-0 truncate px-3 py-2"><a href={l.url} target="_blank" rel="noopener noreferrer" className="hover:underline" title={l.url}>{l.url}</a></td>
                                                <td className="px-3 py-2 text-right tabular-nums">{l.unique_clicks}</td>
                                                <td className="px-3 py-2 text-right tabular-nums">{l.clicks}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </ResponsiveTable>
                        )}
                    </section>
                )}

                <form onSubmit={save} className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    {/* ---- setup ----------------------------------------------- */}
                    <section className={card}>
                        <h2 className="mb-3 font-semibold">Setup</h2>
                        <fieldset disabled={!editable} className="grid gap-3">
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Campaign name <span className="font-normal text-muted-foreground">(only you see this)</span></span>
                                <input className={field} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                {form.errors.name && <span className="text-xs text-destructive">{form.errors.name}</span>}
                            </label>
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Subject line</span>
                                <input className={field} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} placeholder="e.g. {{first_name}}, three shows this weekend" maxLength={200} />
                                <span className="text-xs text-muted-foreground">Use <code>{'{{first_name}}'}</code> to personalise. Avoid ALL CAPS and “FREE!!!” — spam filters read them.</span>
                            </label>
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Preview text</span>
                                <input className={field} value={form.data.preheader} onChange={(e) => form.setData('preheader', e.target.value)} placeholder="The line shown after the subject in the inbox" maxLength={200} />
                            </label>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <label className="grid gap-1.5 text-sm">
                                    <span className="font-medium">From name</span>
                                    <input className={field} value={form.data.from_name} onChange={(e) => form.setData('from_name', e.target.value)} placeholder="DropRSVP" />
                                </label>
                                <label className="grid gap-1.5 text-sm">
                                    <span className="font-medium">Reply-to <span className="font-normal text-muted-foreground">(optional)</span></span>
                                    <input className={field} type="email" value={form.data.reply_to} onChange={(e) => form.setData('reply_to', e.target.value)} placeholder="contact@droprsvp.com" />
                                    {form.errors.reply_to && <span className="text-xs text-destructive">{form.errors.reply_to}</span>}
                                </label>
                            </div>
                            <p className="text-xs text-muted-foreground">Sends from <span className="font-medium text-foreground">{fromAddress}</span>.</p>
                        </fieldset>
                    </section>

                    {/* ---- audience -------------------------------------------- */}
                    <section className={card}>
                        <div className="mb-3 flex items-center justify-between gap-2">
                            <h2 className="font-semibold">Audience</h2>
                            <span className="flex items-center gap-1.5 text-sm font-semibold tabular-nums">
                                {counting ? <Loader2 className="size-3.5 animate-spin" /> : <Users className="size-3.5" />}
                                {count.toLocaleString()} {count === 1 ? 'person' : 'people'}
                            </span>
                        </div>
                        {repermission ? (
                            <div className="grid gap-2 text-sm text-muted-foreground">
                                <p>Everyone with an account or a paid ticket who has <span className="font-medium text-foreground">never</span> chosen either way about DropRSVP emails.</p>
                                <p>Each person can only ever receive one of these. Anyone who already opted in or out, or who was asked before, is left out automatically.</p>
                                <p>Those who click “Yes” join the list. Everyone else is never emailed marketing.</p>
                            </div>
                        ) : (
                            <>
                                <p className="mb-3 text-xs text-muted-foreground">Only subscribers who opted in to DropRSVP emails. Filters narrow that list; nothing here can add anyone.</p>

                        <fieldset disabled={!editable} className="grid gap-4">
                            <div>
                                <div className="mb-1.5 text-xs font-medium">Purchase history</div>
                                <div className="flex flex-wrap gap-1.5">
                                    {([['any', 'Everyone'], ['buyers', 'Have bought a ticket'], ['non_buyers', 'Never bought']] as const).map(([v, l]) => (
                                        <Chip key={v} active={form.data.audience.purchase === v} onClick={() => setAudience({ purchase: v })}>{l}</Chip>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <div className="mb-1.5 text-xs font-medium">Bought within</div>
                                <div className="flex flex-wrap gap-1.5">
                                    {([[null, 'Any time'], [30, '30 days'], [90, '90 days'], [180, '6 months'], [365, 'A year']] as const).map(([v, l]) => (
                                        <Chip key={String(v)} active={form.data.audience.purchased_within_days === v} onClick={() => setAudience({ purchased_within_days: v })}>{l}</Chip>
                                    ))}
                                </div>
                            </div>

                            {options.categories.length > 0 && (
                                <div>
                                    <div className="mb-1.5 text-xs font-medium">Bought in category</div>
                                    <div className="flex flex-wrap gap-1.5">
                                        {options.categories.map((c) => (
                                            <Chip key={c.value} active={form.data.audience.category_ids.includes(Number(c.value))} onClick={() => setAudience({ category_ids: toggle(form.data.audience.category_ids, Number(c.value)) })}>{c.label}</Chip>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <div>
                                <div className="mb-1.5 text-xs font-medium">Bought a ticket for</div>
                                <SearchableSelect
                                    value=""
                                    onChange={(v) => v && setAudience({ event_ids: toggle(form.data.audience.event_ids, Number(v)) })}
                                    options={options.events}
                                    placeholder="Add an event…"
                                    searchPlaceholder="Search events…"
                                />
                                {form.data.audience.event_ids.length > 0 && (
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {form.data.audience.event_ids.map((id) => (
                                            <Chip key={id} active onClick={() => setAudience({ event_ids: form.data.audience.event_ids.filter((x) => x !== id) })}>
                                                {options.events.find((e) => Number(e.value) === id)?.label ?? `Event #${id}`} ×
                                            </Chip>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {options.cities.length > 0 && (
                                <div>
                                    <div className="mb-1.5 text-xs font-medium">City</div>
                                    <div className="flex flex-wrap gap-1.5">
                                        {options.cities.map((c) => (
                                            <Chip key={c} active={form.data.audience.cities.includes(c)} onClick={() => setAudience({ cities: toggle(form.data.audience.cities, c) })}>{c}</Chip>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </fieldset>
                            </>
                        )}
                    </section>

                    {editable && (
                        <div className="flex items-center gap-3 lg:col-span-2">
                            <Button type="submit" disabled={form.processing || !form.isDirty}>{form.processing ? 'Saving…' : 'Save setup & audience'}</Button>
                            {form.isDirty && <span className="text-xs text-muted-foreground">Unsaved changes</span>}
                        </div>
                    )}
                </form>

                {/* ---- content --------------------------------------------- */}
                <section className={`${card} mt-5`}>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-semibold">Email</h2>
                        <div className="flex gap-2">
                            <Button asChild variant="outline" size="sm"><a href={`/admin/edm/campaigns/${campaign.id}/preview`} target="_blank" rel="noopener"><Eye className="size-4" /> Open preview</a></Button>
                            <Button asChild size="sm"><Link href={`/admin/edm/campaigns/${campaign.id}/editor`}><Pencil className="size-4" /> {editable ? 'Design email' : 'View design'}</Link></Button>
                        </div>
                    </div>
                    <p className="mb-3 text-xs text-muted-foreground">Exactly what an inbox receives — rendered by the same code that sends it, with your name filled in.</p>
                    <iframe
                        key={campaign.updated_at ?? ''}
                        src={`/admin/edm/campaigns/${campaign.id}/preview`}
                        title="Email preview"
                        // No scripts, no forms, no navigation of the admin page.
                        sandbox=""
                        className="h-[520px] w-full rounded-xl border border-border bg-white sm:h-[640px]"
                    />
                </section>

                {/* ---- spam check ------------------------------------------ */}
                {spam && campaign.has_content && (
                    <div className="mt-5">
                        <SpamCheckCard result={spam} />
                    </div>
                )}

                {/* ---- test + send ----------------------------------------- */}
                {editable && (
                    <div className="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
                        <section className={card}>
                            <h2 className="mb-1 font-semibold">Send a test</h2>
                            <p className="mb-3 text-xs text-muted-foreground">Up to five addresses. Goes out immediately, outside the hourly limit, and is not counted.</p>
                            <form onSubmit={(e) => {
 e.preventDefault(); test.post(`/admin/edm/campaigns/${campaign.id}/test`, { preserveScroll: true }); 
}} className="grid gap-2">
                                <input className={field} value={test.data.emails} onChange={(e) => test.setData('emails', e.target.value)} placeholder="you@example.com, colleague@example.com" />
                                {test.errors.emails && <span className="text-xs text-destructive">{test.errors.emails}</span>}
                                <div><Button type="submit" variant="outline" disabled={test.processing}><Mail className="size-4" /> {test.processing ? 'Sending…' : 'Send test'}</Button></div>
                            </form>
                        </section>

                        <section className={card}>
                            <h2 className="mb-1 font-semibold">Send</h2>
                            {campaign.status === 'scheduled' ? (
                                <div className="grid gap-3 text-sm">
                                    <p className="flex items-center gap-1.5"><CalendarClock className="size-4" /> Scheduled for <span className="font-medium">{campaign.scheduled_at}</span>.</p>
                                    <div><Button variant="outline" onClick={() => act('unschedule')}>Back to draft</Button></div>
                                </div>
                            ) : (
                                <div className="grid gap-3">
                                    <p className="text-xs text-muted-foreground">
                                        {count.toLocaleString()} {count === 1 ? 'person' : 'people'} · {estimate(count, throttle) || 'nobody to send to yet'} at {throttle.hourly_limit} an hour
                                        {throttle.warmup_daily_cap !== null && ` (warm-up: ${throttle.warmup_daily_cap} a day)`}.
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <Button onClick={sendNow} disabled={!campaign.has_content || !form.data.subject.trim() || count === 0}><Send className="size-4" /> Send now</Button>
                                    </div>
                                    <form onSubmit={(e) => {
 e.preventDefault(); schedule.post(`/admin/edm/campaigns/${campaign.id}/schedule`, { preserveScroll: true }); 
}} className="flex flex-wrap items-center gap-2">
                                        <input type="datetime-local" className={`${field} w-full sm:w-auto`} value={schedule.data.at} onChange={(e) => schedule.setData('at', e.target.value)} />
                                        <Button type="submit" variant="outline" disabled={!schedule.data.at || schedule.processing}><CalendarClock className="size-4" /> Schedule</Button>
                                    </form>
                                    {schedule.errors.at && <span className="text-xs text-destructive">{schedule.errors.at}</span>}
                                    <p className="text-[11px] text-muted-foreground">Times are Malaysian time.</p>
                                </div>
                            )}
                        </section>
                    </div>
                )}
            </div>

            <Dialog open={savingTemplate} onOpenChange={setSavingTemplate}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Save as template</DialogTitle>
                        <DialogDescription>Keeps this design, subject and preview text in your template library for future campaigns. This campaign is unchanged.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={(e) => {
                        e.preventDefault();
                        templateForm.post('/admin/edm/templates', { preserveScroll: true, onSuccess: () => setSavingTemplate(false) });
                    }} className="grid gap-3">
                        <label className="grid gap-1.5 text-sm">
                            <span className="font-medium">Template name</span>
                            <input autoFocus className={field} value={templateForm.data.name} onChange={(e) => templateForm.setData('name', e.target.value)} />
                            {templateForm.errors.name && <span className="text-xs text-destructive">{templateForm.errors.name}</span>}
                        </label>
                        <label className="grid gap-1.5 text-sm">
                            <span className="font-medium">Description <span className="font-normal text-muted-foreground">(optional)</span></span>
                            <input className={field} value={templateForm.data.description} onChange={(e) => templateForm.setData('description', e.target.value)} placeholder="When to use it" />
                        </label>
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button type="button" variant="ghost" onClick={() => setSavingTemplate(false)}>Cancel</Button>
                            <Button type="submit" disabled={templateForm.processing || !templateForm.data.name.trim()}>Save template</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

EdmCampaign.layout = { breadcrumbs: [EDM_CRUMB, { title: 'Campaigns', href: '/admin/edm/campaigns' }, { title: 'Campaign', href: '#' }] };
