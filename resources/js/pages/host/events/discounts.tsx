import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Pencil, Plus, Tag, Ticket, Trash2, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

interface Code {
    id: number; code: string; kind: 'percent' | 'fixed'; value: number; min_subtotal: number | null;
    max_redemptions: number | null; is_active: boolean; starts_at: string | null; ends_at: string | null;
    stats: { redemptions: number; revenue: number; discount_given: number };
}
interface EventInfo { title: string; slug: string; currency: string }

const rm = (n: number) => `RM ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

type FormData = { code: string; kind: 'percent' | 'fixed'; value: string; min_subtotal: string; max_redemptions: string; starts_at: string; ends_at: string; is_active: boolean };
const blank: FormData = { code: '', kind: 'percent', value: '', min_subtotal: '', max_redemptions: '', starts_at: '', ends_at: '', is_active: true };

export default function Discounts({ event, codes }: { event: EventInfo; codes: Code[] }) {
    const confirm = useConfirm();
    const [editing, setEditing] = useState<Code | null | 'new'>(null);
    const form = useForm<FormData>(blank);

    const open = (c: Code | 'new') => {
        if (c === 'new') {
            form.setData(blank);
        } else {
            form.setData({
                code: c.code, kind: c.kind, value: String(c.value), min_subtotal: c.min_subtotal != null ? String(c.min_subtotal) : '',
                max_redemptions: c.max_redemptions != null ? String(c.max_redemptions) : '', starts_at: c.starts_at ?? '', ends_at: c.ends_at ?? '', is_active: c.is_active,
            });
        }

        form.clearErrors();
        setEditing(c);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing === 'new') {
            form.post(`/host/events/${event.slug}/discounts`, opts);
        } else if (editing) {
            form.put(`/host/events/${event.slug}/discounts/${editing.id}`, opts);
        }
    };

    const remove = async (c: Code) => {
        if (await confirm({ title: `Delete “${c.code}”?`, description: 'Existing orders keep their discount; the code just stops working.', confirmText: 'Delete', destructive: true })) {
            router.delete(`/host/events/${event.slug}/discounts/${c.id}`, { preserveScroll: true });
        }
    };

    const label = (c: Code) => c.kind === 'percent' ? `${c.value}% off` : `${rm(c.value)} off`;

    return (
        <>
            <Head title={`Promo codes · ${event.title}`} />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <Link href={`/host/events`} className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> Events</Link>
                <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Promo codes</h1>
                        <p className="text-sm text-muted-foreground">{event.title} — create discount codes and track how they perform.</p>
                    </div>
                    <Button onClick={() => open('new')}><Plus className="size-4" /> New code</Button>
                </div>

                {codes.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border py-16 text-center">
                        <span className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"><Tag className="size-5" /></span>
                        <p className="mt-3 text-sm font-medium">No promo codes yet</p>
                        <p className="mt-1 text-xs text-muted-foreground">Create a code to offer a discount at checkout.</p>
                        <Button className="mt-4" onClick={() => open('new')}><Plus className="size-4" /> New code</Button>
                    </div>
                ) : (
                    <ul className="grid gap-3">
                        {codes.map((c) => (
                            <li key={c.id} className="rounded-xl border border-border bg-card p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-mono text-base font-semibold">{c.code}</span>
                                            <Badge variant="secondary">{label(c)}</Badge>
                                            {c.is_active ? <Badge>Active</Badge> : <Badge variant="outline">Off</Badge>}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {c.min_subtotal ? `Min spend ${rm(c.min_subtotal)} · ` : ''}
                                            {c.max_redemptions ? `${c.stats.redemptions}/${c.max_redemptions} used` : `${c.stats.redemptions} used`}
                                            {c.ends_at ? ` · ends ${c.ends_at}` : ''}
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 gap-2">
                                        <Button size="sm" variant="outline" onClick={() => open(c)}><Pencil className="size-3.5" /> Edit</Button>
                                        <Button size="sm" variant="ghost" className="text-destructive hover:text-destructive" onClick={() => remove(c)}><Trash2 className="size-3.5" /></Button>
                                    </div>
                                </div>
                                <div className="mt-3 grid grid-cols-3 gap-2 border-t border-border pt-3 text-center">
                                    <div><div className="flex items-center justify-center gap-1 text-xs text-muted-foreground"><Ticket className="size-3" /> Redemptions</div><div className="mt-0.5 text-lg font-bold tabular-nums">{c.stats.redemptions}</div></div>
                                    <div><div className="flex items-center justify-center gap-1 text-xs text-muted-foreground"><Tag className="size-3" /> Discount given</div><div className="mt-0.5 text-lg font-bold tabular-nums">{rm(c.stats.discount_given)}</div></div>
                                    <div><div className="flex items-center justify-center gap-1 text-xs text-muted-foreground"><TrendingUp className="size-3" /> Revenue</div><div className="mt-0.5 text-lg font-bold tabular-nums">{rm(c.stats.revenue)}</div></div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Dialog open={!!editing} onOpenChange={(v) => !v && setEditing(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{editing === 'new' ? 'New promo code' : 'Edit promo code'}</DialogTitle>
                        <DialogDescription>Buyers enter this at checkout to get the discount.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="code">Code</Label>
                            <input id="code" className={`${field} font-mono uppercase`} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} placeholder="EARLYBIRD" />
                            {form.errors.code && <p className="text-xs text-destructive">{form.errors.code}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1.5">
                                <Label>Type</Label>
                                <AppSelect value={form.data.kind} onChange={(v) => form.setData('kind', v as 'percent' | 'fixed')} options={[{ value: 'percent', label: 'Percentage' }, { value: 'fixed', label: 'Fixed amount' }]} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="value">{form.data.kind === 'percent' ? 'Percent off' : 'Amount off (RM)'}</Label>
                                <input id="value" type="number" step="0.01" min="0.01" className={field} value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} placeholder={form.data.kind === 'percent' ? '10' : '5.00'} />
                                {form.errors.value && <p className="text-xs text-destructive">{form.errors.value}</p>}
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="min_subtotal">Min spend (RM)</Label>
                                <input id="min_subtotal" type="number" step="0.01" min="0" className={field} value={form.data.min_subtotal} onChange={(e) => form.setData('min_subtotal', e.target.value)} placeholder="Optional" />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="max_redemptions">Max uses</Label>
                                <input id="max_redemptions" type="number" min="1" className={field} value={form.data.max_redemptions} onChange={(e) => form.setData('max_redemptions', e.target.value)} placeholder="Unlimited" />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="starts_at">Starts</Label>
                                <input id="starts_at" type="date" className={field} value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="ends_at">Ends</Label>
                                <input id="ends_at" type="date" className={field} value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                                {form.errors.ends_at && <p className="text-xs text-destructive">{form.errors.ends_at}</p>}
                            </div>
                        </div>
                        <label className="flex items-center justify-between rounded-lg border border-border px-3 py-2.5">
                            <span className="text-sm">Active</span>
                            <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                        </label>
                        <DialogFooter className="mt-1 gap-2">
                            <Button type="button" variant="ghost" onClick={() => setEditing(null)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save code'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

Discounts.layout = { breadcrumbs: [{ title: 'Promo codes', href: '#' }] };
