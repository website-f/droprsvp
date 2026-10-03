import { Head, router } from '@inertiajs/react';
import { Ban, Download, RotateCcw, Search, UserMinus, Users } from 'lucide-react';
import { useState } from 'react';
import { PALETTE } from '@/components/charts';
import { useConfirm } from '@/components/confirm-dialog';
import { EDM_CRUMB, EdmHeader, Pager, Stat } from '@/components/edm/ui';
import { ResponsiveTable } from '@/components/responsive-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

/** `joined`: on an organizer's list, how many of their events this person bought a ticket for (0 = can't be emailed). */
interface Row { id: number; email: string; name: string | null; source: string; detail?: string | null; since: string | null; suppressed: boolean; joined?: number | null }
interface Paginated { data: Row[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number; total: number }
type Status = 'subscribed' | 'unsubscribed' | 'suppressed';

interface Props {
    subscribers: Paginated;
    filters: { q: string; status: Status };
    counts: { subscribed: number; unsubscribed: number; suppressed: number; reachable?: number | null };
    sources: Record<string, number>;
    base?: string;
    /** Platform admins only: suppression protects the shared server. */
    canSuppress?: boolean;
}

const SOURCE_LABEL: Record<string, string> = {
    checkout: 'At checkout',
    register: 'At sign-up',
    settings: 'In settings',
    repermission: 'Re-permission email',
    admin: 'By an admin',
    unsubscribe: 'Unsubscribe link',
    complaint: 'Spam complaint',
    // Suppression reasons
    bounce: 'Bounced',
    manual: 'Blocked by an admin',
};

const TABS: { key: Status; label: string }[] = [
    { key: 'subscribed', label: 'Subscribed' },
    { key: 'unsubscribed', label: 'Unsubscribed' },
    { key: 'suppressed', label: 'Suppressed' },
];

export default function EdmSubscribers({ base = '/admin/edm', canSuppress = true, subscribers, filters, counts, sources }: Props) {
    const confirm = useConfirm();
    const [q, setQ] = useState(filters.q);

    const go = (patch: Partial<Props['filters']>) => router.get(`${base}/subscribers`, { ...filters, q, ...patch }, { preserveState: true, preserveScroll: true });

    const unsubscribe = async (r: Row) => {
        if (await confirm({ title: `Unsubscribe ${r.email}?`, description: 'For when they ask by email or phone. They can opt back in themselves.', confirmText: 'Unsubscribe' })) {
            router.post(`${base}/subscribers/${r.id}/unsubscribe`, {}, { preserveScroll: true });
        }
    };

    const suppress = async (r: Row) => {
        if (await confirm({ title: `Never email ${r.email} again?`, description: 'For a dead or hostile inbox. Applies to every list, and is not undone by opting back in.', confirmText: 'Suppress', destructive: true })) {
            router.post(`${base}/subscribers/${r.id}/suppress`, {}, { preserveScroll: true });
        }
    };

    const unsuppress = async (r: Row) => {
        if (await confirm({ title: `Allow ${r.email} again?`, description: 'Only if the problem is fixed (the mailbox works again, or it was blocked by mistake). They get mail again only if still subscribed.', confirmText: 'Allow again' })) {
            router.post(`${base}/suppressions/${r.id}/remove`, {}, { preserveScroll: true });
        }
    };

    const total = counts.subscribed;

    return (
        <>
            <Head title="Subscribers" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <EdmHeader
                    title="Subscribers"
                    description={canSuppress
                        ? 'People who chose to hear from DropRSVP. There is no import: consent has to come from the person, and bought or copied lists are where spam complaints come from.'
                        : 'People who ticked “email me about future events from you” at checkout. There is no import: consent has to come from the person.'}
                    actions={<Button asChild variant="outline"><a href={`${base}/subscribers/export`}><Download className="size-4" /> Export CSV</a></Button>}
                />

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat
                        icon={Users}
                        label="Subscribed"
                        value={counts.subscribed.toLocaleString()}
                        hint={counts.reachable != null ? `${counts.reachable.toLocaleString()} joined an event — the ones you can email` : undefined}
                        tint={PALETTE[0]}
                    />
                    <Stat icon={UserMinus} label="Unsubscribed" value={counts.unsubscribed.toLocaleString()} tint={PALETTE[7]} />
                    <Stat icon={Ban} label="Suppressed" value={counts.suppressed.toLocaleString()} hint="Never mailed, from any list." tint={PALETTE[3]} />
                    <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
                        <div className="mb-2 text-xs text-muted-foreground">How they joined</div>
                        <div className="grid gap-1.5">
                            {Object.entries(sources).sort(([, a], [, b]) => b - a).slice(0, 4).map(([k, v]) => (
                                <div key={k} className="grid gap-0.5">
                                    <div className="flex justify-between text-[11px]"><span>{SOURCE_LABEL[k] ?? k}</span><span className="tabular-nums text-muted-foreground">{v}</span></div>
                                    <div className="h-1.5 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${Math.round((100 * v) / Math.max(1, total))}%` }} /></div>
                                </div>
                            ))}
                            {Object.keys(sources).length === 0 && <span className="text-xs text-muted-foreground">Nobody yet.</span>}
                        </div>
                    </div>
                </div>

                <section className="mt-6 rounded-2xl border border-border bg-card shadow-sm">
                    <div className="flex flex-col gap-3 border-b border-border p-4 md:flex-row md:items-center md:justify-between">
                        <div className="-mx-1 flex gap-1 overflow-x-auto px-1 [scrollbar-width:none]">
                            {TABS.map((t) => (
                                <button
                                    key={t.key}
                                    type="button"
                                    onClick={() => go({ status: t.key })}
                                    className={`flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${filters.status === t.key ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                                >
                                    {t.label}
                                    <span className={`rounded-full px-1.5 text-[11px] tabular-nums ${filters.status === t.key ? 'bg-background/20' : 'bg-muted'}`}>{counts[t.key].toLocaleString()}</span>
                                </button>
                            ))}
                        </div>
                        <form onSubmit={(e) => {
                            e.preventDefault();
                            go({});
                        }} className="relative">
                            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search email…" className="h-9 w-full rounded-lg border border-input bg-card pl-8 pr-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20 md:w-64" />
                        </form>
                    </div>

                    <ResponsiveTable>
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="px-4 py-3 font-medium">Email</th>
                                    <th className="px-4 py-3 font-medium">{filters.status === 'suppressed' ? 'Reason' : 'How'}</th>
                                    <th className="px-4 py-3 font-medium">{filters.status === 'subscribed' ? 'Since' : filters.status === 'unsubscribed' ? 'Left' : 'When'}</th>
                                    <th className="px-4 py-3 text-right font-medium"><span className="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {subscribers.data.length === 0 ? (
                                    <tr><td colSpan={4} className="px-4 py-12 text-center text-muted-foreground">
                                        {filters.q ? 'No addresses match.'
                                            : filters.status === 'subscribed' ? 'Nobody has opted in yet. They can at checkout, at sign-up, or in their settings.'
                                                : filters.status === 'unsubscribed' ? 'Nobody has unsubscribed.' : 'No suppressed addresses. Bounces and spam complaints land here automatically.'}
                                    </td></tr>
                                ) : subscribers.data.map((r) => (
                                    <tr key={r.id} className="align-top">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{r.email}</div>
                                            {filters.status !== 'suppressed' && (
                                                <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                                    {r.name ?? 'No account'}
                                                    {r.suppressed && <Badge variant="destructive" className="h-4 px-1.5 text-[10px]">Suppressed</Badge>}
                                                    {r.joined != null && (r.joined > 0
                                                        ? <Badge variant="secondary" className="h-4 px-1.5 text-[10px]">Joined {r.joined} event{r.joined === 1 ? '' : 's'}</Badge>
                                                        : <Badge variant="outline" className="h-4 px-1.5 text-[10px]" title="Opted in at checkout but never paid — can't be emailed">No ticket yet</Badge>)}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {SOURCE_LABEL[r.source] ?? r.source}
                                            {r.detail && <div className="max-w-xs text-[11px]">{r.detail}</div>}
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">{r.since ?? '—'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-1">
                                                {filters.status === 'subscribed' && <Button size="sm" variant="ghost" onClick={() => unsubscribe(r)} title="Unsubscribe"><UserMinus className="size-4" /> <span className="md:sr-only">Unsubscribe</span></Button>}
                                                {canSuppress && filters.status !== 'suppressed' && !r.suppressed && <Button size="sm" variant="ghost" onClick={() => suppress(r)} title="Never email again"><Ban className="size-4" /> <span className="md:sr-only">Suppress</span></Button>}
                                                {canSuppress && filters.status === 'suppressed' && <Button size="sm" variant="ghost" onClick={() => unsuppress(r)} title="Allow again"><RotateCcw className="size-4" /> Allow again</Button>}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </ResponsiveTable>

                    <Pager prev={subscribers.prev_page_url} next={subscribers.next_page_url} page={subscribers.current_page} last={subscribers.last_page} />
                </section>
            </div>
        </>
    );
}

EdmSubscribers.layout = { breadcrumbs: [EDM_CRUMB, { title: 'Subscribers', href: '/admin/edm/subscribers' }] };
