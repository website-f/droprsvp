import { Head, Link, router } from '@inertiajs/react';
import { Check, ClipboardCopy, Inbox, ListChecks, RefreshCw, Server, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { EDM_CRUMB, EdmHeader, Pager, Section, StatusPill } from '@/components/edm/ui';
import { ResponsiveTable } from '@/components/responsive-table';
import { Button } from '@/components/ui/button';

interface CheckItem { id: string; label: string; status: string; detail: string }
interface DnsRecord { name: string; label: string; status: string; detail: string; record: string | null; host: string; suggest: string | null }
interface Listing { target: string; list: string; label: string; status: string; detail: string | null; checked: string | null; since: string | null }
interface Bounce { id: number; email: string; type: string; code: string | null; diagnostic: string | null; campaign: { id: number; name: string } | null; when: string | null }
interface Props {
    checklist: CheckItem[];
    dns: DnsRecord[];
    domain: string | null;
    blocklists: Listing[];
    sendingIps: string[];
    bounceSetup: {
        configured: boolean; host: string | null; port: number | null; username: string | null; folder: string;
        last: { at: string; fetched?: number; bounces?: number; suppressed?: number; error?: string | null; via?: string } | null;
        pipe: string;
    };
    bounceTotals: Record<string, number>;
    bounces: { data: Bounce[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number; total: number };
    filters: { type: string };
}

const TYPE_LABEL: Record<string, string> = { hard: 'Hard', soft: 'Soft', blocked: 'Blocked', complaint: 'Complaint' };
const TYPE_HINT: Record<string, string> = {
    hard: 'Address does not exist — suppressed',
    soft: 'Temporary — suppressed after 3 in 30 days',
    blocked: 'Refused us, not the reader — never suppresses',
    complaint: 'Marked as spam — suppressed and unsubscribed',
};
const TYPE_CLS: Record<string, string> = {
    hard: 'bg-rose-500/10 text-rose-700 dark:text-rose-400',
    complaint: 'bg-rose-500/10 text-rose-700 dark:text-rose-400',
    soft: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    blocked: 'bg-violet-500/10 text-violet-700 dark:text-violet-400',
};

function Copyable({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/40 p-2">
            <code className="min-w-0 flex-1 break-all text-[11px] leading-relaxed">{value}</code>
            <button
                type="button"
                onClick={() => {
                    void navigator.clipboard?.writeText(value).then(() => {
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 1500);
                    });
                }}
                className="shrink-0 rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                aria-label="Copy"
            >
                {copied ? <Check className="size-3.5" /> : <ClipboardCopy className="size-3.5" />}
            </button>
        </div>
    );
}

export default function EdmDeliverability({ checklist, dns, domain, blocklists, sendingIps, bounceSetup, bounceTotals, bounces, filters }: Props) {
    const [busy, setBusy] = useState<'' | 'check' | 'bounces'>('');
    const passing = checklist.filter((c) => c.status === 'pass').length;
    const post = (what: 'check' | 'bounces') => {
        setBusy(what);
        router.post(`/admin/edm/deliverability/${what}`, {}, { preserveScroll: true, onFinish: () => setBusy('') });
    };
    const listed = blocklists.filter((b) => b.status === 'listed');

    return (
        <>
            <Head title="Deliverability" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <EdmHeader
                    title="Deliverability"
                    description="Whether campaign mail reaches inboxes: the setup, the sending domain’s DNS, blocklists, and what bounced."
                    actions={<Button variant="outline" onClick={() => post('check')} disabled={busy !== ''}><RefreshCw className={`size-4 ${busy === 'check' ? 'animate-spin' : ''}`} /> Re-run checks</Button>}
                />

                {/* ---- checklist ----------------------------------------------- */}
                <Section
                    title={<span className="flex items-center gap-2"><ListChecks className="size-4" /> Setup checklist</span>}
                    description={`${passing} of ${checklist.length} done. Work down the list — each item says what to change.`}
                >
                    <div className="mb-4 h-2 overflow-hidden rounded-full bg-muted">
                        <div className="h-full rounded-full bg-emerald-500 transition-all" style={{ width: `${Math.round((100 * passing) / Math.max(1, checklist.length))}%` }} />
                    </div>
                    <ul className="grid grid-cols-1 gap-2 md:grid-cols-2">
                        {checklist.map((c) => (
                            <li key={c.id} className="flex items-start gap-3 rounded-xl border border-border p-3">
                                <StatusPill status={c.status} />
                                <div className="min-w-0 text-sm">
                                    <div className="font-medium">{c.label}</div>
                                    <div className="text-xs text-muted-foreground">{c.detail}</div>
                                </div>
                            </li>
                        ))}
                    </ul>
                </Section>

                {/* ---- DNS ----------------------------------------------------- */}
                <Section
                    className="mt-4"
                    title={<span className="flex items-center gap-2"><ShieldCheck className="size-4" /> Sending domain {domain && <span className="font-normal text-muted-foreground">· {domain}</span>}</span>}
                    description="Gmail and Yahoo require SPF, DKIM and DMARC from bulk senders. Checked live each time this page opens."
                >
                    {dns.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Set EDM_FROM_ADDRESS in .env to the address campaigns are sent from.</p>
                    ) : (
                        <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                            {dns.map((d) => (
                                <div key={d.name} className="flex flex-col gap-2 rounded-xl border border-border p-4">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-semibold">{d.label}</span>
                                        <StatusPill status={d.status} />
                                    </div>
                                    <p className="text-xs text-muted-foreground">{d.detail}</p>
                                    <div className="text-[11px] text-muted-foreground">TXT record at <span className="font-mono text-foreground">{d.host}</span></div>
                                    {d.record && <Copyable value={d.record} />}
                                    {d.status !== 'pass' && d.suggest && (
                                        <div className="grid gap-1">
                                            <span className="text-[11px] font-medium">Suggested record</span>
                                            <Copyable value={d.suggest} />
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </Section>

                {/* ---- blocklists ---------------------------------------------- */}
                <Section
                    className="mt-4"
                    title={<span className="flex items-center gap-2"><Server className="size-4" /> Blocklists</span>}
                    description={sendingIps.length ? `Sending IP ${sendingIps.join(', ')}${domain ? ` and ${domain}` : ''}. Checked daily at 7am; admins are alerted on a listing.` : 'No sending IP known — set EDM_SENDING_IP (or EDM_MAIL_HOST) so the IP can be checked.'}
                    padded={false}
                >
                    {listed.length > 0 && (
                        <div className="mx-4 mt-4 rounded-xl border border-rose-500/40 bg-rose-500/5 p-3 text-sm">
                            <span className="font-medium">Listed on {listed.length} list{listed.length === 1 ? '' : 's'}.</span> Mail — including tickets from the same server — may be refused. Use each list’s delisting form, then re-run the check.
                        </div>
                    )}
                    {blocklists.length === 0 ? (
                        <div className="p-6 text-center text-sm text-muted-foreground">Not checked yet. <button type="button" className="font-medium text-foreground underline" onClick={() => post('check')}>Run the check</button>.</div>
                    ) : (
                        <ResponsiveTable className="p-4">
                            <table className="w-full min-w-[640px] text-sm">
                                <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <tr className="border-b border-border"><th className="px-3 py-2 font-medium">List</th><th className="px-3 py-2 font-medium">Checked</th><th className="px-3 py-2 font-medium">Status</th><th className="px-3 py-2 font-medium">Since</th><th className="px-3 py-2 font-medium">Last checked</th></tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {blocklists.map((b) => (
                                        <tr key={`${b.target}-${b.list}`}>
                                            <td className="px-3 py-2"><div className="font-medium">{b.label}</div><div className="text-[11px] text-muted-foreground">{b.list}</div></td>
                                            <td className="px-3 py-2 font-mono text-xs">{b.target}</td>
                                            <td className="px-3 py-2"><StatusPill status={b.status} />{b.detail && b.status !== 'clean' && <div className="mt-1 max-w-xs text-[11px] text-muted-foreground">{b.detail}</div>}</td>
                                            <td className="px-3 py-2 text-xs text-muted-foreground">{b.since}</td>
                                            <td className="px-3 py-2 text-xs text-muted-foreground">{b.checked}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </ResponsiveTable>
                    )}
                </Section>

                {/* ---- bounces ------------------------------------------------- */}
                <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Section
                        title={<span className="flex items-center gap-2"><Inbox className="size-4" /> Bounce mailbox</span>}
                        description="Bounces and spam complaints are read back every ten minutes."
                        actions={<Button size="sm" variant="outline" onClick={() => post('bounces')} disabled={busy !== '' || !bounceSetup.configured}><RefreshCw className={`size-3.5 ${busy === 'bounces' ? 'animate-spin' : ''}`} /> Read now</Button>}
                    >
                        <dl className="grid gap-2 text-sm">
                            <div className="flex justify-between gap-3"><dt className="text-muted-foreground">Status</dt><dd>{!bounceSetup.configured ? <StatusPill status="fail" label="Not set up" />
                                : !bounceSetup.last ? <StatusPill status="warn" label="Not read yet" />
                                    : <StatusPill status={bounceSetup.last.error ? 'fail' : 'pass'} label={bounceSetup.last.error ? 'Error' : 'Working'} />}</dd></div>
                            {bounceSetup.host && <div className="flex justify-between gap-3"><dt className="text-muted-foreground">Server</dt><dd className="truncate font-mono text-xs">{bounceSetup.host}:{bounceSetup.port}</dd></div>}
                            {bounceSetup.username && <div className="flex justify-between gap-3"><dt className="text-muted-foreground">Mailbox</dt><dd className="truncate font-mono text-xs">{bounceSetup.username} · {bounceSetup.folder}</dd></div>}
                            {bounceSetup.last && <div className="flex justify-between gap-3"><dt className="text-muted-foreground">Last read</dt><dd className="text-xs">{new Date(bounceSetup.last.at).toLocaleString()}</dd></div>}
                        </dl>
                        {bounceSetup.last?.error && <p className="mt-3 rounded-lg bg-rose-500/5 p-2 text-xs text-rose-700 dark:text-rose-400">{bounceSetup.last.error}</p>}
                        {!bounceSetup.configured && <p className="mt-3 text-xs text-muted-foreground">Uses the EDM_MAIL_* login by default. Set those, or EDM_BOUNCE_HOST / _USERNAME / _PASSWORD, in .env.</p>}
                        <details className="mt-3 text-xs text-muted-foreground">
                            <summary className="cursor-pointer font-medium text-foreground">Prefer cPanel piping?</summary>
                            <p className="my-2">In cPanel → Forwarders, forward the sending address to “Pipe to a Program” with:</p>
                            <Copyable value={bounceSetup.pipe} />
                        </details>
                    </Section>

                    <Section className="lg:col-span-2" title="Last 30 days" description="Every bounce and complaint read back, by kind.">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {(['hard', 'soft', 'blocked', 'complaint'] as const).map((t) => (
                                <button
                                    key={t}
                                    type="button"
                                    onClick={() => router.get('/admin/edm/deliverability', filters.type === t ? {} : { type: t }, { preserveScroll: true, preserveState: true })}
                                    className={`rounded-xl border p-3 text-left transition-colors ${filters.type === t ? 'border-foreground' : 'border-border hover:border-foreground/30'}`}
                                >
                                    <div className="text-2xl font-bold tabular-nums">{(bounceTotals[t] ?? 0).toLocaleString()}</div>
                                    <div className="text-xs font-medium">{TYPE_LABEL[t]}</div>
                                    <div className="text-[11px] text-muted-foreground">{TYPE_HINT[t]}</div>
                                </button>
                            ))}
                        </div>
                    </Section>
                </div>

                <Section className="mt-4" padded={false} title={`Bounce log${filters.type ? ` · ${TYPE_LABEL[filters.type]}` : ''}`} description={`${bounces.total.toLocaleString()} recorded`}
                    actions={filters.type ? <button type="button" className="text-xs font-medium text-muted-foreground hover:text-foreground" onClick={() => router.get('/admin/edm/deliverability', {}, { preserveScroll: true })}>Show all</button> : undefined}
                >
                    <ResponsiveTable>
                        <table className="w-full min-w-[760px] text-sm">
                            <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr className="border-b border-border"><th className="px-4 py-2 font-medium">Address</th><th className="px-4 py-2 font-medium">Kind</th><th className="px-4 py-2 font-medium">Reason</th><th className="px-4 py-2 font-medium">Campaign</th><th className="px-4 py-2 font-medium">When</th></tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {bounces.data.length === 0 ? (
                                    <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">No bounces recorded{filters.type ? ' of this kind' : ''}.</td></tr>
                                ) : bounces.data.map((b) => (
                                    <tr key={b.id} className="align-top">
                                        <td className="px-4 py-2 font-medium">{b.email}</td>
                                        <td className="px-4 py-2"><span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${TYPE_CLS[b.type] ?? 'bg-muted'}`}>{TYPE_LABEL[b.type] ?? b.type}</span></td>
                                        <td className="max-w-sm px-4 py-2 text-xs text-muted-foreground">{b.code && <span className="mr-1 font-mono text-foreground">{b.code}</span>}{b.diagnostic ?? '—'}</td>
                                        <td className="px-4 py-2 text-xs">{b.campaign ? <Link href={`/admin/edm/campaigns/${b.campaign.id}`} className="hover:underline">{b.campaign.name}</Link> : '—'}</td>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">{b.when}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </ResponsiveTable>
                    <Pager prev={bounces.prev_page_url} next={bounces.next_page_url} page={bounces.current_page} last={bounces.last_page} />
                </Section>
            </div>
        </>
    );
}

EdmDeliverability.layout = { breadcrumbs: [EDM_CRUMB, { title: 'Deliverability', href: '/admin/edm/deliverability' }] };
