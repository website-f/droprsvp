import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Ban, Download, Search, UserMinus } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { ResponsiveTable } from '@/components/responsive-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Row { id: number; email: string; name: string | null; source: string; since: string | null; suppressed: boolean }
interface Paginated { data: Row[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number; total: number }

interface Props {
    subscribers: Paginated;
    filters: { q: string; status: 'subscribed' | 'unsubscribed' };
    counts: { subscribed: number; unsubscribed: number; suppressed: number };
    sources: Record<string, number>;
}

const SOURCE_LABEL: Record<string, string> = {
    checkout: 'At checkout',
    register: 'At sign-up',
    settings: 'In settings',
    repermission: 'Re-permission email',
    admin: 'By an admin',
    unsubscribe: 'Unsubscribe link',
};

export default function EdmSubscribers({ subscribers, filters, counts, sources }: Props) {
    const confirm = useConfirm();
    const [q, setQ] = useState(filters.q);

    const go = (patch: Partial<Props['filters']>) => router.get('/admin/edm/subscribers', { ...filters, q, ...patch }, { preserveState: true, preserveScroll: true });

    const unsubscribe = async (r: Row) => {
        if (await confirm({ title: `Unsubscribe ${r.email}?`, description: 'For when they ask by email or phone. They can opt back in themselves.', confirmText: 'Unsubscribe' })) {
            router.post(`/admin/edm/subscribers/${r.id}/unsubscribe`, {}, { preserveScroll: true });
        }
    };

    const suppress = async (r: Row) => {
        if (await confirm({ title: `Never email ${r.email} again?`, description: 'For a dead or hostile inbox. Applies to every list, and is not undone by opting back in.', confirmText: 'Suppress', destructive: true })) {
            router.post(`/admin/edm/subscribers/${r.id}/suppress`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Subscribers" />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <Link href="/admin/edm/campaigns" className="mb-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" /> Campaigns</Link>
                <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Subscribers</h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            People who chose to hear from DropRSVP. There is no import: consent has to come from the person, and bought or copied lists are where spam complaints come from.
                        </p>
                    </div>
                    <Button asChild variant="outline"><a href="/admin/edm/subscribers/export"><Download className="size-4" /> Export CSV</a></Button>
                </div>

                <div className="mb-4 flex flex-wrap gap-2 text-xs text-muted-foreground">
                    {Object.entries(sources).map(([k, v]) => (
                        <span key={k} className="rounded-full border border-border px-3 py-1">{SOURCE_LABEL[k] ?? k}: <span className="font-semibold text-foreground">{v}</span></span>
                    ))}
                    {counts.suppressed > 0 && <span className="rounded-full border border-border px-3 py-1">Suppressed (never mailed): <span className="font-semibold text-foreground">{counts.suppressed}</span></span>}
                </div>

                <div className="mb-4 flex flex-wrap items-center gap-2">
                    {(['subscribed', 'unsubscribed'] as const).map((s) => (
                        <button
                            key={s}
                            onClick={() => go({ status: s })}
                            className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${filters.status === s ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}
                        >
                            {s === 'subscribed' ? `Subscribed (${counts.subscribed})` : `Unsubscribed (${counts.unsubscribed})`}
                        </button>
                    ))}
                    <form onSubmit={(e) => {
 e.preventDefault(); go({}); 
}} className="ml-auto flex items-center gap-2">
                        <label className="relative">
                            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search email…" className="h-9 w-56 rounded-lg border border-input bg-card pl-8 pr-3 text-sm outline-none focus-visible:border-ring" />
                        </label>
                    </form>
                </div>

                <ResponsiveTable className="rounded-2xl border border-border">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">Email</th>
                                <th className="px-4 py-3 font-medium">How</th>
                                <th className="px-4 py-3 font-medium">{filters.status === 'subscribed' ? 'Since' : 'Left'}</th>
                                <th className="px-4 py-3 text-right font-medium"><span className="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {subscribers.data.length === 0 ? (
                                <tr><td colSpan={4} className="px-4 py-12 text-center text-muted-foreground">{filters.status === 'subscribed' ? 'Nobody has opted in yet. They can at checkout, at sign-up, or in their settings.' : 'Nobody has unsubscribed.'}</td></tr>
                            ) : subscribers.data.map((r) => (
                                <tr key={r.id}>
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{r.email}</div>
                                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">{r.name ?? 'No account'} {r.suppressed && <Badge variant="destructive" className="h-4 px-1.5 text-[10px]">Suppressed</Badge>}</div>
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">{SOURCE_LABEL[r.source] ?? r.source}</td>
                                    <td className="px-4 py-3 text-muted-foreground">{r.since ?? '—'}</td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex justify-end gap-1">
                                            {filters.status === 'subscribed' && <Button size="sm" variant="ghost" onClick={() => unsubscribe(r)} title="Unsubscribe"><UserMinus className="size-4" /></Button>}
                                            {!r.suppressed && <Button size="sm" variant="ghost" onClick={() => suppress(r)} title="Never email again"><Ban className="size-4" /></Button>}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </ResponsiveTable>

                {(subscribers.prev_page_url || subscribers.next_page_url) && (
                    <div className="mt-6 flex items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">Page {subscribers.current_page} of {subscribers.last_page}</span>
                        <div className="flex gap-2">
                            <Button asChild variant="outline" size="sm" disabled={!subscribers.prev_page_url}>{subscribers.prev_page_url ? <Link href={subscribers.prev_page_url} preserveScroll>← Prev</Link> : <span>← Prev</span>}</Button>
                            <Button asChild variant="outline" size="sm" disabled={!subscribers.next_page_url}>{subscribers.next_page_url ? <Link href={subscribers.next_page_url} preserveScroll>Next →</Link> : <span>Next →</span>}</Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

EdmSubscribers.layout = { breadcrumbs: [{ title: 'Email marketing', href: '/admin/edm/campaigns' }, { title: 'Subscribers', href: '/admin/edm/subscribers' }] };
