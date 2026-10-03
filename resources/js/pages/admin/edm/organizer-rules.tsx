import { Head, Link, useForm } from '@inertiajs/react';
import { Coins, Gauge, KeyRound, Plus, Power, ShieldAlert, Timer, Trash2, Users } from 'lucide-react';
import { PALETTE } from '@/components/charts';
import { ACCESS_MODES, FEATURE_FIELDS, LIMIT_FIELDS } from '@/components/edm/organizer-rules';
import type { FeatureKey, LimitKey } from '@/components/edm/organizer-rules';
import { EDM_CRUMB, EdmHeader, field, Section, Stat } from '@/components/edm/ui';
import { Button } from '@/components/ui/button';

interface Pack { key: string; name: string; credits: number; price: number }
interface Props {
    rules: Record<LimitKey, number> & Record<FeatureKey, boolean> & {
        enabled: boolean; access: 'all' | 'premium' | 'selected';
        premium_allowance: number; free_allowance: number; packs: Pack[];
        guard: { min_sent: number; bounce_rate: number; unsubscribe_rate: number; complaint_rate: number };
    };
    stats: { organizers: number; sending30: number; custom: number; platform_hourly: number };
}

const pct = (n: number) => Math.round(n * 10000) / 100;

function Toggle({ checked, onChange, label, hint, danger }: { checked: boolean; onChange: (v: boolean) => void; label: string; hint?: string; danger?: boolean }) {
    return (
        <label className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition-colors ${checked ? (danger ? 'border-amber-500/40 bg-amber-500/5' : 'border-primary/40 bg-primary/5') : 'border-border'}`}>
            <input type="checkbox" className="mt-1" checked={checked} onChange={(e) => onChange(e.target.checked)} />
            <span><span className="block text-sm font-medium">{label}</span>{hint && <span className="text-xs text-muted-foreground">{hint}</span>}</span>
        </label>
    );
}

export default function OrganizerRules({ rules, stats }: Props) {
    const form = useForm({
        enabled: rules.enabled,
        access: rules.access,
        ...Object.fromEntries(LIMIT_FIELDS.map((f) => [f.key, rules[f.key]])) as Record<LimitKey, number>,
        ...Object.fromEntries(FEATURE_FIELDS.map((f) => [f.key, rules[f.key]])) as Record<FeatureKey, boolean>,
        premium_allowance: rules.premium_allowance,
        free_allowance: rules.free_allowance,
        packs: rules.packs,
        guard: { min_sent: rules.guard.min_sent, bounce_rate: pct(rules.guard.bounce_rate), unsubscribe_rate: pct(rules.guard.unsubscribe_rate), complaint_rate: pct(rules.guard.complaint_rate) },
    });
    const d = form.data;
    const err = form.errors as Record<string, string | undefined>;
    const hourlyTooHigh = d.hourly_limit > 0 && d.hourly_limit > stats.platform_hourly;

    const setPack = (i: number, patch: Partial<Pack>) => form.setData('packs', d.packs.map((p, j) => (j === i ? { ...p, ...patch } : p)));

    return (
        <>
            <Head title="Organizer rules" />
            <form
                className="mx-auto w-full max-w-5xl flex-1 p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/admin/edm/organizer-rules', { preserveScroll: true });
                }}
            >
                <EdmHeader
                    title="Organizer rules"
                    description="What organizers may send through DropRSVP’s email, to the people who joined their events. These apply to everyone; give one organizer different rules in EDM → Organizers."
                />

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Stat icon={Users} label="Organizers" value={stats.organizers.toLocaleString()} tint={PALETTE[0]} />
                    <Stat icon={Gauge} label="Sent campaigns, 30 days" value={stats.sending30.toLocaleString()} hint="organizers" tint={PALETTE[2]} />
                    <Stat icon={KeyRound} label="With their own rules" value={stats.custom.toLocaleString()} hint={<Link href="/admin/edm/organizers" className="underline">Manage</Link>} tint={PALETTE[4]} />
                    <Stat icon={Timer} label="Platform limit / hour" value={stats.platform_hourly.toLocaleString()} hint={<Link href="/admin/edm/settings" className="underline">All campaigns share it</Link>} tint={PALETTE[6]} />
                </div>

                <div className="mt-4 grid gap-4">
                    <Section title={<span className="flex items-center gap-2"><Power className="size-4" /> Access</span>} description="Who can use email marketing at all.">
                        <Toggle
                            checked={d.enabled}
                            onChange={(v) => form.setData('enabled', v)}
                            label="Email marketing for organizers is on"
                            hint="The master switch. Off hides it from every organizer and pauses their sending campaigns — your own campaigns are not affected."
                        />
                        <fieldset className="mt-4 grid gap-2 sm:grid-cols-3" disabled={!d.enabled}>
                            {ACCESS_MODES.map((m) => (
                                <label key={m.value} className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 ${d.access === m.value ? 'border-primary bg-primary/5' : 'border-border'} ${d.enabled ? '' : 'opacity-50'}`}>
                                    <input type="radio" name="access" className="mt-1" checked={d.access === m.value} onChange={() => form.setData('access', m.value)} />
                                    <span><span className="block text-sm font-medium">{m.label}</span><span className="text-xs text-muted-foreground">{m.hint}</span></span>
                                </label>
                            ))}
                        </fieldset>
                        <p className="mt-3 text-xs text-muted-foreground">
                            Whatever the mode, an organizer can only ever email people who <strong className="text-foreground">bought a ticket (or registered) for one of their events</strong> and ticked “email me” at that checkout. Following them, or ticking the box and not paying, is not enough.
                        </p>
                    </Section>

                    <Section title={<span className="flex items-center gap-2"><Gauge className="size-4" /> Volume</span>} description="Per organizer. 0 means no limit of that kind (the platform-wide hourly limit always applies).">
                        <div className="grid gap-4 sm:grid-cols-3">
                            {LIMIT_FIELDS.filter((f) => f.group === 'volume').map((f) => (
                                <label key={f.key} className="grid content-start gap-1.5 text-sm">
                                    <span className="font-medium">{f.label}</span>
                                    <input type="number" min={0} className={field} value={d[f.key]} onChange={(e) => form.setData(f.key, Number(e.target.value))} />
                                    <span className="text-[11px] text-muted-foreground">{f.hint}</span>
                                    {err[f.key] && <span className="text-xs text-destructive">{err[f.key]}</span>}
                                </label>
                            ))}
                        </div>
                        {hourlyTooHigh && <p className="mt-3 text-xs text-amber-600 dark:text-amber-400">Higher than the platform’s own {stats.platform_hourly.toLocaleString()} an hour, so that one binds first.</p>}
                    </Section>

                    <Section title={<span className="flex items-center gap-2"><Timer className="size-4" /> Size and frequency</span>} description="How big a campaign can be and how often organizers can send. 0 means no limit.">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {LIMIT_FIELDS.filter((f) => f.group === 'frequency').map((f) => (
                                <label key={f.key} className="grid content-start gap-1.5 text-sm">
                                    <span className="font-medium">{f.label}</span>
                                    <input type="number" min={0} className={field} value={d[f.key]} onChange={(e) => form.setData(f.key, Number(e.target.value))} />
                                    <span className="text-[11px] text-muted-foreground">{f.hint}</span>
                                    {err[f.key] && <span className="text-xs text-destructive">{err[f.key]}</span>}
                                </label>
                            ))}
                        </div>
                    </Section>

                    <Section title="Features" description="Each can also be switched per organizer.">
                        <div className="grid gap-2 md:grid-cols-3">
                            {FEATURE_FIELDS.map((f) => (
                                <Toggle key={f.key} checked={d[f.key]} onChange={(v) => form.setData(f.key, v)} label={f.label} hint={f.hint} danger={f.key === 'abandoned_checkout'} />
                            ))}
                        </div>
                    </Section>

                    <Section title={<span className="flex items-center gap-2"><Coins className="size-4" /> Pricing</span>} description="One email, one credit. Free monthly emails are spent first, then credits organizers buy through CHIP.">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Free emails / month — Premium organizers</span>
                                <input type="number" min={0} className={field} value={d.premium_allowance} onChange={(e) => form.setData('premium_allowance', Number(e.target.value))} />
                            </label>
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Free emails / month — other organizers</span>
                                <input type="number" min={0} className={field} value={d.free_allowance} onChange={(e) => form.setData('free_allowance', Number(e.target.value))} />
                            </label>
                        </div>

                        <div className="mt-5">
                            <div className="mb-2 flex items-center justify-between">
                                <span className="text-sm font-medium">Credit packs</span>
                                {d.packs.length < 6 && <Button type="button" size="sm" variant="outline" onClick={() => form.setData('packs', [...d.packs, { key: '', name: '', credits: 1000, price: 10 }])}><Plus className="size-4" /> Add pack</Button>}
                            </div>
                            <div className="grid gap-2">
                                {d.packs.map((p, i) => (
                                    <div key={i} className="grid grid-cols-2 items-end gap-2 rounded-xl border border-border p-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                        <label className="col-span-2 grid gap-1 text-xs sm:col-span-1">Name<input className={field} value={p.name} onChange={(e) => setPack(i, { name: e.target.value })} placeholder="Starter" /></label>
                                        <label className="grid gap-1 text-xs">Credits<input type="number" min={1} className={field} value={p.credits} onChange={(e) => setPack(i, { credits: Number(e.target.value) })} /></label>
                                        <label className="grid gap-1 text-xs">Price (RM)<input type="number" min={0} step="0.01" className={field} value={p.price} onChange={(e) => setPack(i, { price: Number(e.target.value) })} /></label>
                                        <div className="col-span-2 flex items-center justify-between gap-2 sm:col-span-1 sm:justify-end">
                                            <span className="text-[11px] text-muted-foreground sm:hidden">RM {(p.price / Math.max(1, p.credits) * 1000).toFixed(2)} per 1,000</span>
                                            <Button type="button" size="icon" variant="ghost" aria-label="Remove pack" onClick={() => form.setData('packs', d.packs.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Button>
                                        </div>
                                    </div>
                                ))}
                                {d.packs.length === 0 && <p className="text-xs text-muted-foreground">No packs: organizers can only use their free monthly emails.</p>}
                                {Object.entries(err).filter(([k]) => k.startsWith('packs')).slice(0, 1).map(([k, v]) => <span key={k} className="text-xs text-destructive">{v}</span>)}
                            </div>
                        </div>
                    </Section>

                    <Section title={<span className="flex items-center gap-2"><ShieldAlert className="size-4" /> Automatic suspension</span>} description="Judged over 30 days. Past any rate, the organizer’s sending is suspended until you review it in EDM → Organizers.">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <label className="grid gap-1.5 text-sm"><span className="font-medium">Judge after (emails)</span><input type="number" min={1} className={field} value={d.guard.min_sent} onChange={(e) => form.setData('guard', { ...d.guard, min_sent: Number(e.target.value) })} /></label>
                            <label className="grid gap-1.5 text-sm"><span className="font-medium">Bounce rate above (%)</span><input type="number" min={0} step="0.1" className={field} value={d.guard.bounce_rate} onChange={(e) => form.setData('guard', { ...d.guard, bounce_rate: Number(e.target.value) })} /></label>
                            <label className="grid gap-1.5 text-sm"><span className="font-medium">Unsubscribes above (%)</span><input type="number" min={0} step="0.1" className={field} value={d.guard.unsubscribe_rate} onChange={(e) => form.setData('guard', { ...d.guard, unsubscribe_rate: Number(e.target.value) })} /></label>
                            <label className="grid gap-1.5 text-sm"><span className="font-medium">Spam complaints above (%)</span><input type="number" min={0} step="0.01" className={field} value={d.guard.complaint_rate} onChange={(e) => form.setData('guard', { ...d.guard, complaint_rate: Number(e.target.value) })} /></label>
                        </div>
                    </Section>
                </div>

                <div className="sticky bottom-0 -mx-4 mt-4 flex items-center justify-end gap-3 border-t border-border bg-background/90 px-4 py-3 backdrop-blur">
                    {form.isDirty && <span className="text-xs text-muted-foreground">Unsaved changes</span>}
                    <Button type="submit" disabled={form.processing || !form.isDirty}>{form.processing ? 'Saving…' : 'Save rules'}</Button>
                </div>
            </form>
        </>
    );
}

OrganizerRules.layout = { breadcrumbs: [EDM_CRUMB, { title: 'Organizer rules', href: '/admin/edm/organizer-rules' }] };
