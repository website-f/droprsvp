import { Head, router, useForm } from '@inertiajs/react';
import { Ban, Coins, Mail, PlayCircle, Search, Settings2, ShieldAlert, Users } from 'lucide-react';
import { useState } from 'react';
import { PALETTE } from '@/components/charts';
import { EDM_CRUMB, EdmHeader, field, Stat, StatusPill } from '@/components/edm/ui';
import { ResponsiveTable } from '@/components/responsive-table';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

interface Row {
    id: number; name: string; email: string; premium: boolean; status: 'active' | 'suspended'; reason: string | null; suspended_at: string | null;
    subscribers: number; campaigns: number; sent30: number; bounce_rate: number; unsub_rate: number; complaints: number;
    credits: number; allowance: number; allowance_used: number; allowance_override: number | null; domains: string[];
}
interface Props {
    organizers: Row[];
    filters: { q: string; filter: string };
    limits: { min_sent: number; bounce_rate: number; unsubscribe_rate: number; complaint_rate: number };
    totals: { organizers: number; suspended: number; sent30: number; credits_sold: number };
}

export default function EdmOrganizers({ organizers, filters, limits, totals }: Props) {
    const [q, setQ] = useState(filters.q);
    const [suspending, setSuspending] = useState<Row | null>(null);
    const [adjusting, setAdjusting] = useState<Row | null>(null);
    const suspendForm = useForm({ reason: '' });
    const adjustForm = useForm({ credits: '', note: '', monthly_allowance: '', reset_allowance: false });

    const go = (patch: Partial<Props['filters']>) => router.get('/admin/edm/organizers', Object.fromEntries(Object.entries({ ...filters, q, ...patch }).filter(([, v]) => v)), { preserveState: true, preserveScroll: true });

    const over = (r: Row) => r.sent30 >= limits.min_sent && (r.bounce_rate > limits.bounce_rate * 100 || r.unsub_rate > limits.unsubscribe_rate * 100);

    return (
        <>
            <Head title="Organizer EDM" />
            <div className="mx-auto w-full max-w-6xl flex-1 p-4">
                <EdmHeader title="Organizers" description="Every organizer’s email usage. Accounts the guardrails suspended are listed first, for review." />

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat icon={Users} label="Organizers using EDM" value={totals.organizers.toLocaleString()} tint={PALETTE[0]} />
                    <Stat icon={ShieldAlert} label="Suspended, awaiting review" value={totals.suspended.toLocaleString()} tint={PALETTE[3]} />
                    <Stat icon={Mail} label="Sent by organizers, 30 days" value={totals.sent30.toLocaleString()} tint={PALETTE[2]} />
                    <Stat icon={Coins} label="Credits sold, all time" value={totals.credits_sold.toLocaleString()} tint={PALETTE[6]} />
                </div>

                <p className="mt-4 text-xs text-muted-foreground">
                    Automatic suspension over 30 days, once {limits.min_sent.toLocaleString()} emails have gone out: bounces above {(limits.bounce_rate * 100).toFixed(0)}%, unsubscribes above {(limits.unsubscribe_rate * 100).toFixed(0)}%, or spam complaints above {(limits.complaint_rate * 100).toFixed(1)}%.
                </p>

                <section className="mt-4 rounded-2xl border border-border bg-card shadow-sm">
                    <div className="flex flex-col gap-3 border-b border-border p-4 md:flex-row md:items-center md:justify-between">
                        <div className="flex gap-1">
                            {[['', 'All'], ['suspended', 'Suspended'], ['active', 'Active']].map(([k, l]) => (
                                <button key={k} type="button" onClick={() => go({ filter: k })} className={`rounded-lg px-3 py-1.5 text-sm font-medium ${filters.filter === k ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}>{l}</button>
                            ))}
                        </div>
                        <form onSubmit={(e) => {
                            e.preventDefault();
                            go({ q });
                        }} className="relative">
                            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search organizers…" className="h-9 w-full rounded-lg border border-input bg-card pl-8 pr-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20 md:w-64" />
                        </form>
                    </div>

                    <ResponsiveTable>
                        <table className="w-full min-w-[980px] text-sm">
                            <thead className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="px-4 py-3 font-medium">Organizer</th>
                                    <th className="px-4 py-3 font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Subscribers</th>
                                    <th className="px-4 py-3 text-right font-medium">Sent 30d</th>
                                    <th className="px-4 py-3 text-right font-medium">Bounce</th>
                                    <th className="px-4 py-3 text-right font-medium">Unsub</th>
                                    <th className="px-4 py-3 text-right font-medium">Complaints</th>
                                    <th className="px-4 py-3 text-right font-medium">Free / month</th>
                                    <th className="px-4 py-3 text-right font-medium">Credits</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {organizers.length === 0 ? (
                                    <tr><td colSpan={10} className="px-4 py-12 text-center text-muted-foreground">No organizer has used email marketing yet.</td></tr>
                                ) : organizers.map((r) => (
                                    <tr key={r.id} className="align-top">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{r.name}</div>
                                            <div className="text-xs text-muted-foreground">{r.email}{r.premium && ' · Premium'}</div>
                                            {r.domains.length > 0 && <div className="text-[11px] text-muted-foreground">Sends from {r.domains.join(', ')}</div>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusPill status={r.status === 'suspended' ? 'fail' : over(r) ? 'warn' : 'pass'} label={r.status === 'suspended' ? 'Suspended' : over(r) ? 'Over limit' : 'Active'} />
                                            {r.reason && <div className="mt-1 max-w-[16rem] text-[11px] text-muted-foreground">{r.reason}{r.suspended_at && ` · ${r.suspended_at}`}</div>}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{r.subscribers.toLocaleString()}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{r.sent30.toLocaleString()}</td>
                                        <td className={`px-4 py-3 text-right tabular-nums ${r.bounce_rate > limits.bounce_rate * 100 ? 'text-rose-600 dark:text-rose-400' : ''}`}>{r.bounce_rate}%</td>
                                        <td className={`px-4 py-3 text-right tabular-nums ${r.unsub_rate > limits.unsubscribe_rate * 100 ? 'text-rose-600 dark:text-rose-400' : ''}`}>{r.unsub_rate}%</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{r.complaints}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{r.allowance_used.toLocaleString()} / {r.allowance.toLocaleString()}{r.allowance_override !== null && <div className="text-[10px] text-muted-foreground">custom</div>}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{r.credits.toLocaleString()}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-1">
                                                {r.status === 'suspended'
                                                    ? <Button size="sm" variant="outline" onClick={() => router.post(`/admin/edm/organizers/${r.id}/reinstate`, {}, { preserveScroll: true })}><PlayCircle className="size-4" /> Reinstate</Button>
                                                    : <Button size="sm" variant="ghost" onClick={() => {
                                                        suspendForm.reset();
                                                        setSuspending(r);
                                                    }}><Ban className="size-4" /> <span className="md:sr-only">Suspend</span></Button>}
                                                <Button size="sm" variant="ghost" onClick={() => {
                                                    adjustForm.setData({ credits: '', note: '', monthly_allowance: r.allowance_override !== null ? String(r.allowance_override) : '', reset_allowance: false });
                                                    setAdjusting(r);
                                                }}><Settings2 className="size-4" /> <span className="md:sr-only">Adjust</span></Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </ResponsiveTable>
                </section>
            </div>

            <Dialog open={suspending !== null} onOpenChange={(o) => !o && setSuspending(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Suspend {suspending?.name}?</DialogTitle>
                        <DialogDescription>Their sending campaigns pause and they cannot send until you reinstate them. They see the reason.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={(e) => {
                        e.preventDefault();
                        suspendForm.post(`/admin/edm/organizers/${suspending?.id}/suspend`, { preserveScroll: true, onSuccess: () => setSuspending(null) });
                    }} className="grid gap-3">
                        <textarea className={`${field} h-24 py-2`} value={suspendForm.data.reason} onChange={(e) => suspendForm.setData('reason', e.target.value)} placeholder="e.g. Mailing a purchased list — complaints from recipients." />
                        {suspendForm.errors.reason && <span className="text-xs text-destructive">{suspendForm.errors.reason}</span>}
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button type="button" variant="ghost" onClick={() => setSuspending(null)}>Cancel</Button>
                            <Button type="submit" variant="destructive" disabled={suspendForm.processing || !suspendForm.data.reason.trim()}>Suspend sending</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={adjusting !== null} onOpenChange={(o) => !o && setAdjusting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Adjust {adjusting?.name}</DialogTitle>
                        <DialogDescription>Grant (or remove) credits, or give them a monthly allowance of their own.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={(e) => {
                        e.preventDefault();
                        adjustForm.post(`/admin/edm/organizers/${adjusting?.id}/adjust`, { preserveScroll: true, onSuccess: () => setAdjusting(null) });
                    }} className="grid gap-3">
                        <label className="grid gap-1.5 text-sm">
                            <span className="font-medium">Credits to add <span className="font-normal text-muted-foreground">(negative removes)</span></span>
                            <input type="number" className={field} value={adjustForm.data.credits} onChange={(e) => adjustForm.setData('credits', e.target.value)} placeholder="e.g. 500" />
                        </label>
                        <label className="grid gap-1.5 text-sm">
                            <span className="font-medium">Note <span className="font-normal text-muted-foreground">(they see it in their history)</span></span>
                            <input className={field} value={adjustForm.data.note} onChange={(e) => adjustForm.setData('note', e.target.value)} placeholder="e.g. Goodwill credit" />
                        </label>
                        <label className="grid gap-1.5 text-sm">
                            <span className="font-medium">Free emails per month</span>
                            <input type="number" min={0} className={field} value={adjustForm.data.monthly_allowance} onChange={(e) => adjustForm.setData('monthly_allowance', e.target.value)} placeholder={`Plan default: ${adjusting?.allowance ?? 0}`} disabled={adjustForm.data.reset_allowance} />
                        </label>
                        {adjusting?.allowance_override !== null && (
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={adjustForm.data.reset_allowance} onChange={(e) => adjustForm.setData('reset_allowance', e.target.checked)} />
                                Back to the plan default
                            </label>
                        )}
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <Button type="button" variant="ghost" onClick={() => setAdjusting(null)}>Cancel</Button>
                            <Button type="submit" disabled={adjustForm.processing}>Save</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

EdmOrganizers.layout = { breadcrumbs: [EDM_CRUMB, { title: 'Organizers', href: '/admin/edm/organizers' }] };
