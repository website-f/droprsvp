import { Head, Link, router, useForm } from '@inertiajs/react';
import { Activity, Ban, Flag, Gauge, Loader2, Megaphone, MessagesSquare, Settings2, ShieldAlert, Trash2, UserX } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { EdmHeader, field, Pager, Section, Stat } from '@/components/edm/ui';
import { ResponsiveTable } from '@/components/responsive-table';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type Tab = 'reports' | 'users' | 'ips' | 'broadcasts' | 'settings';
interface Who { id: number; name: string; email: string }
interface Report {
    id: number; reason: string; details: string | null; status: string; action: string | null;
    reporter: Who | null; reported: (Who & { reports: number; suspended: boolean }) | null;
    message: { id: number; body: string | null; image: boolean; deleted: boolean } | null;
    conversation_id: number | null; when: string;
}
interface ContextMessage { id: number; sender: string; reported_user: boolean; body: string | null; image: string | null; deleted: boolean; flagged: boolean; ip: string | null; at: string }
interface Props {
    tab: Tab;
    stats: { messages_today: number; conversations_week: number; open_reports: number; suspended: number; ip_bans: number; polls_per_second: number };
    reports: { data: Report[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number } | null;
    reportStatus: 'open' | 'dismissed' | 'actioned';
    suspended: { id: number; name: string; email: string; reason: string | null; until: string | null }[] | null;
    ipBans: { id: number; ip: string; reason: string | null; automatic: boolean; active: boolean; until: string | null; when: string }[] | null;
    broadcasts: { id: number; title: string; body: string; audience: string; recipients: number; when: string }[] | null;
    audiences: { value: string; label: string }[];
    settings: Record<string, number | boolean>;
}

const TABS: { key: Tab; label: string; icon: typeof Flag }[] = [
    { key: 'reports', label: 'Reports', icon: Flag },
    { key: 'users', label: 'Suspended', icon: UserX },
    { key: 'ips', label: 'IP bans', icon: Ban },
    { key: 'broadcasts', label: 'Announcements', icon: Megaphone },
    { key: 'settings', label: 'Settings', icon: Settings2 },
];

const DURATIONS = [{ v: 1, l: '1 day' }, { v: 7, l: '7 days' }, { v: 30, l: '30 days' }, { v: 0, l: 'Permanently' }];

export default function ChatModeration({ tab, stats, reports, reportStatus, suspended, ipBans, broadcasts, audiences, settings }: Props) {
    const tabs = useRef<HTMLElement>(null);

    // On a phone the tab strip scrolls sideways: keep the current tab in view.
    useEffect(() => {
        const el = tabs.current?.querySelector<HTMLElement>('[data-active="true"]');

        if (el && tabs.current) {
            tabs.current.scrollLeft = el.offsetLeft - (tabs.current.clientWidth - el.clientWidth) / 2;
        }
    }, [tab]);

    return (
        <>
            <Head title="Chat moderation" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <EdmHeader title="Chat" description="Reports from members, suspensions, IP bans, announcements to every inbox, and the live-update tuning." />

                <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Stat icon={MessagesSquare} label="Messages today" value={stats.messages_today.toLocaleString()} tint="#6c63ff" />
                    <Stat icon={Activity} label="Active chats (7d)" value={stats.conversations_week.toLocaleString()} tint="#3b82f6" />
                    <Stat icon={Flag} label="Open reports" value={stats.open_reports} tint={stats.open_reports ? '#ef4444' : '#22c55e'} />
                    <Stat icon={UserX} label="Suspended" value={stats.suspended} tint="#f97316" />
                    <Stat icon={Ban} label="IP bans" value={stats.ip_bans} tint="#a855f7" />
                    <Stat icon={Gauge} label="Polls / second" value={stats.polls_per_second} hint={`Slows down above ${settings.max_polls_per_second}`} tint="#14b8a6" />
                </div>

                <nav ref={tabs} className="-mx-4 mb-4 flex gap-1 overflow-x-auto px-4 pb-1">
                    {TABS.map((t) => (
                        <Link key={t.key} href={`/admin/chat?tab=${t.key}`} data-active={tab === t.key} preserveScroll className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-medium transition-colors ${tab === t.key ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted'}`}>
                            <t.icon className="size-4" /> {t.label}
                            {t.key === 'reports' && stats.open_reports > 0 && <span className="rounded-full bg-destructive px-1.5 text-[10px] text-white">{stats.open_reports}</span>}
                        </Link>
                    ))}
                </nav>

                {tab === 'reports' && reports && <Reports reports={reports} status={reportStatus} />}
                {tab === 'users' && suspended && <Suspended rows={suspended} />}
                {tab === 'ips' && ipBans && <IpBans rows={ipBans} />}
                {tab === 'broadcasts' && broadcasts && <Broadcasts rows={broadcasts} audiences={audiences} />}
                {tab === 'settings' && <SettingsForm settings={settings} />}
            </div>
        </>
    );
}

// ---- reports -----------------------------------------------------------------------------

function Reports({ reports, status }: { reports: NonNullable<Props['reports']>; status: Props['reportStatus'] }) {
    const [open, setOpen] = useState<Report | null>(null);

    return (
        <Section padded={false} title="Reports" description="Reports about the same person are settled together. You see a conversation only through a report about it."
            actions={(
                <div className="flex gap-1">
                    {(['open', 'actioned', 'dismissed'] as const).map((s) => (
                        <Link key={s} href={`/admin/chat?tab=reports&status=${s}`} preserveScroll className={`rounded-lg px-2.5 py-1 text-xs font-medium capitalize ${status === s ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted'}`}>{s}</Link>
                    ))}
                </div>
            )}
        >
            {reports.data.length === 0 ? (
                <p className="px-4 py-12 text-center text-sm text-muted-foreground">{status === 'open' ? 'Nothing to review. 🎉' : `No ${status} reports.`}</p>
            ) : (
                <ul className="divide-y divide-border">
                    {reports.data.map((r) => (
                        <li key={r.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <span className="rounded-full bg-destructive/10 px-2 py-0.5 text-[11px] font-semibold text-destructive">{r.reason}</span>
                                    <span className="font-semibold">{r.reported?.name ?? 'Deleted user'}</span>
                                    {r.reported && <span className="text-xs text-muted-foreground">{r.reported.email} · {r.reported.reports} report{r.reported.reports === 1 ? '' : 's'}</span>}
                                    {r.reported?.suspended && <span className="rounded-full bg-orange-500/10 px-2 py-0.5 text-[11px] font-medium text-orange-600">Suspended</span>}
                                </div>
                                {r.message && (
                                    <blockquote className="mt-2 rounded-xl border-l-4 border-destructive/50 bg-muted/50 px-3 py-2 text-sm">
                                        {r.message.deleted ? <em className="text-muted-foreground">Message removed</em> : <>{r.message.image && '📷 '}{r.message.body ?? (r.message.image ? 'Photo' : '')}</>}
                                    </blockquote>
                                )}
                                {r.details && <p className="mt-2 text-sm text-muted-foreground">“{r.details}”</p>}
                                <p className="mt-2 text-xs text-muted-foreground">Reported by {r.reporter?.name ?? 'a deleted user'} · {r.when}{r.action && <> · <span className="font-medium text-foreground">{r.action}</span></>}</p>
                            </div>
                            <Button size="sm" variant={r.status === 'open' ? 'default' : 'outline'} onClick={() => setOpen(r)}>{r.status === 'open' ? 'Review' : 'View'}</Button>
                        </li>
                    ))}
                </ul>
            )}
            <Pager prev={reports.prev_page_url} next={reports.next_page_url} page={reports.current_page} last={reports.last_page} />
            <ReviewDialog report={open} onClose={() => setOpen(null)} />
        </Section>
    );
}

function ReviewDialog({ report, onClose }: { report: Report | null; onClose: () => void }) {
    const [messages, setMessages] = useState<ContextMessage[] | null>(null);
    const [loadedFor, setLoadedFor] = useState<number | null>(null);
    const [days, setDays] = useState(7);
    const [busy, setBusy] = useState(false);

    if (report && loadedFor !== report.id) {
        setLoadedFor(report.id);
        setMessages(null);
        fetch(`/admin/chat/reports/${report.id}/context`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => setMessages(d.messages ?? []))
            .catch(() => setMessages([]));
    }

    const resolve = (action: string) => {
        if (!report) {
            return;
        }

        setBusy(true);
        router.post(`/admin/chat/reports/${report.id}/resolve`, { action, days }, {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Dialog open={report !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="flex max-h-[92svh] flex-col sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Report about {report?.reported?.name}</DialogTitle>
                    <DialogDescription>{report?.reason}{report?.details ? ` — “${report.details}”` : ''}</DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 overflow-y-auto rounded-xl border border-border bg-muted/20 p-3">
                    {messages === null && <div className="flex justify-center py-10"><Loader2 className="size-5 animate-spin text-muted-foreground" /></div>}
                    {messages?.length === 0 && <p className="py-10 text-center text-sm text-muted-foreground">No messages to show.</p>}
                    <div className="grid gap-2">
                        {messages?.map((m) => (
                            <div key={m.id} className={`flex flex-col ${m.reported_user ? 'items-start' : 'items-end'}`}>
                                <span className="mb-0.5 text-[10px] text-muted-foreground">{m.sender} · {m.at}{m.ip && m.reported_user ? ` · ${m.ip}` : ''}</span>
                                <div className={`max-w-[85%] rounded-2xl px-3 py-2 text-sm ${m.flagged ? 'ring-2 ring-destructive' : ''} ${m.reported_user ? 'bg-card shadow-sm' : 'bg-primary/10'}`}>
                                    {m.deleted && <em className="mr-1 text-xs text-muted-foreground">[removed]</em>}
                                    {m.image && <img src={m.image} alt="" className="mb-1 max-h-48 rounded-lg" />}
                                    <span className="whitespace-pre-wrap break-words">{m.body}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {report?.status === 'open' ? (
                    <div className="grid gap-3">
                        <label className="flex flex-wrap items-center gap-2 text-sm">
                            Suspend / ban for
                            <select value={days} onChange={(e) => setDays(Number(e.target.value))} className={`${field} w-auto`}>
                                {DURATIONS.map((d) => <option key={d.v} value={d.v}>{d.l}</option>)}
                            </select>
                        </label>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <Button variant="outline" disabled={busy} onClick={() => resolve('dismiss')}>Dismiss</Button>
                            <Button variant="outline" disabled={busy || !report.message || report.message.deleted} onClick={() => resolve('remove_message')}>Remove message</Button>
                            <Button variant="destructive" disabled={busy} onClick={() => resolve('suspend')}><UserX className="size-4" /> Suspend</Button>
                            <Button variant="destructive" disabled={busy} onClick={() => resolve('ban_ip')}><ShieldAlert className="size-4" /> Ban IP</Button>
                        </div>
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">Handled: {report?.action ?? report?.status}.</p>
                )}
            </DialogContent>
        </Dialog>
    );
}

// ---- suspended users --------------------------------------------------------------------

function Suspended({ rows }: { rows: NonNullable<Props['suspended']> }) {
    const confirm = useConfirm();

    return (
        <Section padded={false} title="Suspended from messaging" description="They can still read their messages, but can’t send. Suspend from a report, or lift a suspension here.">
            <ResponsiveTable>
                <table className="w-full min-w-[640px] text-sm">
                    <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr className="border-b border-border"><th className="px-4 py-2 font-medium">Member</th><th className="px-4 py-2 font-medium">Reason</th><th className="px-4 py-2 font-medium">Until</th><th className="px-4 py-2 font-medium" /></tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {rows.length === 0 ? (
                            <tr><td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">No one is suspended.</td></tr>
                        ) : rows.map((s) => (
                            <tr key={s.id}>
                                <td className="px-4 py-2"><div className="font-medium">{s.name}</div><div className="text-xs text-muted-foreground">{s.email}</div></td>
                                <td className="px-4 py-2 text-xs text-muted-foreground">{s.reason ?? '—'}</td>
                                <td className="px-4 py-2 text-xs">{s.until ?? 'Permanently'}</td>
                                <td className="px-4 py-2 text-right">
                                    <Button size="sm" variant="outline" onClick={async () => {
                                        if (await confirm({ title: `Let ${s.name} message again?`, confirmText: 'Lift suspension' })) {
                                            router.post(`/admin/chat/users/${s.id}/unsuspend`, {}, { preserveScroll: true });
                                        }
                                    }}>Lift</Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </ResponsiveTable>
        </Section>
    );
}

// ---- IP bans ----------------------------------------------------------------------------

function IpBans({ rows }: { rows: NonNullable<Props['ipBans']> }) {
    const confirm = useConfirm();
    const form = useForm({ ip: '', hours: 24, reason: '' });

    return (
        <div className="grid gap-4">
            <Section title="Ban an IP address" description="Blocks chat and live updates from that address. Clients that hammer the poll endpoint are banned automatically for the time set in Settings.">
                <form className="grid gap-3 sm:grid-cols-[1fr_auto_1fr_auto] sm:items-end" onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/admin/chat/ip-bans', { preserveScroll: true, onSuccess: () => form.reset() });
                }}>
                    <label className="grid gap-1 text-sm">IP address<input value={form.data.ip} onChange={(e) => form.setData('ip', e.target.value)} placeholder="203.0.113.7" className={field} required />{form.errors.ip && <span className="text-xs text-destructive">{form.errors.ip}</span>}</label>
                    <label className="grid gap-1 text-sm">For
                        <select value={form.data.hours} onChange={(e) => form.setData('hours', Number(e.target.value))} className={field}>
                            <option value={1}>1 hour</option><option value={24}>1 day</option><option value={168}>7 days</option><option value={720}>30 days</option><option value={0}>Permanently</option>
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">Reason<input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="Optional" className={field} /></label>
                    <Button type="submit" variant="destructive" disabled={form.processing}><Ban className="size-4" /> Ban</Button>
                </form>
            </Section>

            <Section padded={false} title="Bans" description="The latest 200, newest first.">
                <ResponsiveTable>
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr className="border-b border-border"><th className="px-4 py-2 font-medium">IP</th><th className="px-4 py-2 font-medium">Reason</th><th className="px-4 py-2 font-medium">Until</th><th className="px-4 py-2 font-medium">Added</th><th className="px-4 py-2 font-medium" /></tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {rows.length === 0 ? (
                                <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">No IP bans.</td></tr>
                            ) : rows.map((b) => (
                                <tr key={b.id} className={b.active ? '' : 'opacity-50'}>
                                    <td className="px-4 py-2 font-mono text-xs font-medium">{b.ip}</td>
                                    <td className="px-4 py-2 text-xs text-muted-foreground">{b.automatic && <span className="mr-1 rounded-full bg-violet-500/10 px-2 py-0.5 text-[10px] font-medium text-violet-600">Auto</span>}{b.reason ?? '—'}</td>
                                    <td className="px-4 py-2 text-xs">{b.active ? (b.until ?? 'Permanently') : 'Expired'}</td>
                                    <td className="px-4 py-2 text-xs text-muted-foreground">{b.when}</td>
                                    <td className="px-4 py-2 text-right">
                                        {b.active && <Button size="sm" variant="outline" onClick={async () => {
                                            if (await confirm({ title: `Unban ${b.ip}?`, confirmText: 'Unban' })) {
                                                router.delete(`/admin/chat/ip-bans/${b.id}`, { preserveScroll: true });
                                            }
                                        }}>Unban</Button>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </ResponsiveTable>
            </Section>
        </div>
    );
}

// ---- announcements -------------------------------------------------------------------------

function Broadcasts({ rows, audiences }: { rows: NonNullable<Props['broadcasts']>; audiences: Props['audiences'] }) {
    const confirm = useConfirm();
    const form = useForm({ title: '', body: '', audience: audiences[0]?.value ?? 'all' });

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
            <Section title="New announcement" description="Appears in the DropRSVP thread at the top of every inbox in the audience, live, without sending email.">
                <form className="grid gap-3" onSubmit={async (e) => {
                    e.preventDefault();

                    const label = audiences.find((a) => a.value === form.data.audience)?.label ?? form.data.audience;

                    if (await confirm({ title: 'Send this announcement?', description: `To: ${label}. It shows up in their inbox straight away.`, confirmText: 'Send' })) {
                        form.post('/admin/chat/broadcasts', { preserveScroll: true, onSuccess: () => form.reset('title', 'body') });
                    }
                }}>
                    <label className="grid gap-1 text-sm">Audience
                        <select value={form.data.audience} onChange={(e) => form.setData('audience', e.target.value)} className={field}>
                            {audiences.map((a) => <option key={a.value} value={a.value}>{a.label}</option>)}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">Title<input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} maxLength={160} className={field} required />{form.errors.title && <span className="text-xs text-destructive">{form.errors.title}</span>}</label>
                    <label className="grid gap-1 text-sm">Message
                        <textarea value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} rows={6} maxLength={4000} className={`${field} h-auto py-2`} required />
                        <span className="text-right text-[11px] text-muted-foreground">{form.data.body.length}/4000</span>
                        {form.errors.body && <span className="text-xs text-destructive">{form.errors.body}</span>}
                    </label>
                    <Button type="submit" disabled={form.processing}><Megaphone className="size-4" /> Send announcement</Button>
                </form>
            </Section>

            <Section padded={false} title="Sent" description="Deleting one removes it from every inbox.">
                {rows.length === 0 ? <p className="px-4 py-12 text-center text-sm text-muted-foreground">No announcements yet.</p> : (
                    <ul className="divide-y divide-border">
                        {rows.map((b) => (
                            <li key={b.id} className="flex gap-3 p-4">
                                <div className="min-w-0 flex-1">
                                    <div className="font-semibold">{b.title}</div>
                                    <p className="mt-0.5 line-clamp-3 whitespace-pre-wrap text-sm text-muted-foreground">{b.body}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">{b.audience} · {b.recipients.toLocaleString()} people · {b.when}</p>
                                </div>
                                <Button size="icon" variant="ghost" aria-label="Delete announcement" onClick={async () => {
                                    if (await confirm({ title: 'Delete this announcement?', description: 'It disappears from every inbox.', confirmText: 'Delete', destructive: true })) {
                                        router.delete(`/admin/chat/broadcasts/${b.id}`, { preserveScroll: true });
                                    }
                                }}><Trash2 className="size-4" /></Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>
        </div>
    );
}

// ---- settings -----------------------------------------------------------------------------

const GROUPS: { title: string; description: string; fields: [string, string, string][] }[] = [
    {
        title: 'Live updates',
        description: 'How often an open page asks for news, in seconds. The server picks one per answer and every browser obeys it — lower is snappier but costs more requests.',
        fields: [
            ['poll_active', 'Active conversation', 'Someone wrote in the last 2 minutes'],
            ['poll_open', 'Open conversation', 'Quiet chat on screen'],
            ['poll_inbox', 'Inbox', 'Messages page, nothing open'],
            ['poll_badge', 'Other pages', 'Just the unread badge'],
            ['poll_hidden', 'Background tab', 'Minimum while the tab is hidden'],
            ['max_polls_per_second', 'Load ceiling (polls/sec)', 'Above this, every interval stretches up to 4×'],
        ],
    },
    {
        title: 'Anti-spam',
        description: 'Limits per sender. Messages to strangers arrive as requests until accepted.',
        fields: [
            ['messages_per_minute', 'Messages per minute', ''],
            ['new_conversations_per_day', 'New chats per day', ''],
            ['new_account_conversations_per_day', 'New chats per day (accounts < 24h)', ''],
            ['request_message_limit', 'Messages before a request is accepted', ''],
            ['duplicate_limit', 'Same text to N people in 10 min = spam', ''],
            ['max_image_mb', 'Max image size (MB)', ''],
        ],
    },
    {
        title: 'Abuse protection',
        description: 'A client that keeps tripping the poll limit is banned by IP automatically.',
        fields: [
            ['poll_limit_per_minute', 'Polls per minute per user / IP', ''],
            ['ban_strikes', 'Strikes before an automatic ban', 'Within 10 minutes'],
            ['ban_minutes', 'Automatic ban length (minutes)', ''],
        ],
    },
    {
        title: 'Email',
        description: 'A digest email for messages left unread. Never sent while the person is online.',
        fields: [
            ['notify_after_minutes', 'Email after unread for (minutes)', ''],
            ['notify_cooldown_hours', 'At most one email every (hours)', ''],
        ],
    },
];

function SettingsForm({ settings }: { settings: Props['settings'] }) {
    const form = useForm<Record<string, number | boolean>>({ ...settings });

    return (
        <form className="grid gap-4" onSubmit={(e) => {
            e.preventDefault();
            form.post('/admin/chat/settings', { preserveScroll: true });
        }}>
            <Section title="Switches">
                <div className="grid gap-3 sm:grid-cols-2">
                    {([['enabled', 'Messaging on', 'Off stops new messages site-wide; inboxes stay readable.'], ['images_enabled', 'Image attachments', 'Let people send photos.']] as const).map(([key, label, hint]) => (
                        <label key={key} className="flex cursor-pointer items-start gap-3 rounded-xl border border-border p-3">
                            <input type="checkbox" className="mt-1" checked={!!form.data[key]} onChange={(e) => form.setData(key, e.target.checked)} />
                            <span><span className="block text-sm font-medium">{label}</span><span className="text-xs text-muted-foreground">{hint}</span></span>
                        </label>
                    ))}
                </div>
            </Section>

            {GROUPS.map((g) => (
                <Section key={g.title} title={g.title} description={g.description}>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {g.fields.map(([key, label, hint]) => (
                            <label key={key} className="grid gap-1 text-sm">
                                <span className="font-medium">{label}</span>
                                <input type="number" min={0} value={String(form.data[key] ?? '')} onChange={(e) => form.setData(key, Number(e.target.value))} className={field} />
                                {hint && <span className="text-[11px] text-muted-foreground">{hint}</span>}
                                {form.errors[key] && <span className="text-xs text-destructive">{form.errors[key]}</span>}
                            </label>
                        ))}
                    </div>
                </Section>
            ))}

            <div className="sticky bottom-0 -mx-4 flex justify-end border-t border-border bg-background/90 px-4 py-3 backdrop-blur">
                <Button type="submit" disabled={form.processing || !form.isDirty}>{form.processing && <Loader2 className="size-4 animate-spin" />} Save settings</Button>
            </div>
        </form>
    );
}

ChatModeration.layout = { breadcrumbs: [{ title: 'Chat', href: '/admin/chat' }] };
