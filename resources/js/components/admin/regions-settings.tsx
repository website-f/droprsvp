import { useForm } from '@inertiajs/react';
import { Check, Globe, Plus, Search, Star, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export interface CatalogCountry { country: string; name: string; currency: string; timezone: string; area: string; flag: string }

/**
 * Admin → Settings → Regions: which countries the site runs in, and the
 * default one. Search any country, add several at once (or a whole part of
 * the world), remove one with its ×. Saved on its own.
 */
export function RegionsSettings({ initial, catalog, areas, events }: {
    initial: { enabled: string[]; default: string };
    catalog: CatalogCountry[];
    areas: string[];
    events: Record<string, number>;
}) {
    const form = useForm({ enabled: initial.enabled, default: initial.default });
    const { data, setData, processing, errors } = form;
    const [q, setQ] = useState('');
    const [area, setArea] = useState('');
    const [picked, setPicked] = useState<string[]>([]);

    const byCode = useMemo(() => Object.fromEntries(catalog.map((c) => [c.country, c])), [catalog]);
    const on = data.enabled.map((c) => byCode[c]).filter(Boolean).sort((a, b) => (a.country === data.default ? -1 : b.country === data.default ? 1 : a.name.localeCompare(b.name)));
    const eventsIn = (country: string) => events[`en-${country.toLowerCase()}`] ?? 0;

    const available = useMemo(() => {
        const term = q.trim().toLowerCase();

        return catalog.filter((c) => !data.enabled.includes(c.country)
            && (!area || c.area === area)
            && (!term || c.name.toLowerCase().includes(term) || c.country.toLowerCase() === term || c.currency.toLowerCase() === term));
    }, [catalog, data.enabled, q, area]);

    const add = (codes: string[]) => {
        setData('enabled', [...new Set([...data.enabled, ...codes])]);
        setPicked([]);
    };
    const remove = (code: string) => setData('enabled', data.enabled.filter((c) => c !== code));
    const toggle = (code: string) => setPicked((p) => (p.includes(code) ? p.filter((c) => c !== code) : [...p, code]));
    const dirty = data.default !== initial.default || data.enabled.length !== initial.enabled.length || data.enabled.some((c) => !initial.enabled.includes(c));

    return (
        <div className="grid gap-6">
            <div className="rounded-xl border border-border p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="flex items-center gap-2 text-sm font-semibold"><Globe className="size-4" /> Regions that are on <span className="font-normal text-muted-foreground">({on.length})</span></h2>
                        <p className="mt-0.5 text-xs text-muted-foreground">Each region gets its own pages — /en-sg/, /en-id/… — listing only its events. Organizers pick a region for each event.</p>
                    </div>
                    <Button onClick={() => form.post('/admin/settings/regions', { preserveScroll: true })} disabled={processing || !dirty}>Save regions</Button>
                </div>

                <div className="mt-4 grid gap-1.5 sm:max-w-xs">
                    <label className="text-sm font-medium">Default region</label>
                    <AppSelect value={data.default} onChange={(v) => setData('default', v)} options={on.map((c) => ({ value: c.country, label: `${c.flag} ${c.name}` }))} />
                    <p className="text-xs text-muted-foreground">Where “/” opens for a first-time visitor whose country is not on, and the home of the blog and help pages.</p>
                    {errors.default && <p className="text-xs text-destructive">{errors.default}</p>}
                </div>

                <ul className="mt-4 flex flex-wrap gap-2">
                    {on.map((c) => (
                        <li key={c.country} className={cn('inline-flex items-center gap-1.5 rounded-full border py-1 pr-1 pl-3 text-sm', c.country === data.default ? 'border-foreground' : 'border-border')}>
                            <span aria-hidden>{c.flag}</span>
                            <span>{c.name}</span>
                            {c.country === data.default && <Star className="size-3 fill-current" aria-label="Default" />}
                            {eventsIn(c.country) > 0 && <span className="rounded-full bg-muted px-1.5 text-[11px] text-muted-foreground">{eventsIn(c.country)} event{eventsIn(c.country) === 1 ? '' : 's'}</span>}
                            <button
                                type="button"
                                onClick={() => remove(c.country)}
                                disabled={c.country === data.default}
                                title={c.country === data.default ? 'Pick another default region first' : `Turn ${c.name} off`}
                                aria-label={`Turn ${c.name} off`}
                                className="flex size-6 items-center justify-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-30"
                            >
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
                {(errors.enabled || errors['enabled.0']) && <p className="mt-2 text-xs text-destructive">{errors.enabled ?? errors['enabled.0']}</p>}
                <p className="mt-3 text-xs text-muted-foreground">Turning a region off hides its pages; its events stay safe and come back when you turn it on again.</p>
            </div>

            <div className="rounded-xl border border-border p-4">
                <h2 className="flex items-center gap-2 text-sm font-semibold"><Plus className="size-4" /> Add regions</h2>
                <div className="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_14rem]">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search a country, code or currency" aria-label="Search countries"
                            className="h-10 w-full rounded-lg border border-input bg-card pr-3 pl-9 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" />
                    </div>
                    <AppSelect value={area || 'all'} onChange={(v) => setArea(v === 'all' ? '' : v)} options={[{ value: 'all', label: 'Every part of the world' }, ...areas.map((a) => ({ value: a, label: a }))]} />
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-2">
                    {picked.length > 0 && <Button size="sm" onClick={() => add(picked)}><Plus className="size-3.5" /> Add {picked.length} selected</Button>}
                    {area && available.length > 0 && (
                        <Button size="sm" variant="outline" onClick={() => add(available.map((c) => c.country))}>Add all {available.length} in {area}</Button>
                    )}
                    {!area && !q && available.some((c) => c.area === 'Southeast Asia') && (
                        <Button size="sm" variant="outline" onClick={() => add(catalog.filter((c) => c.area === 'Southeast Asia').map((c) => c.country))}>Add all of Southeast Asia</Button>
                    )}
                </div>

                <ul className="mt-3 grid max-h-96 gap-1 overflow-y-auto [grid-template-columns:repeat(auto-fill,minmax(min(100%,14rem),1fr))]">
                    {available.length === 0 && <li className="px-2 py-4 text-sm text-muted-foreground">{q || area ? 'No country matches — or it is already on.' : 'Every country is already on.'}</li>}
                    {available.map((c) => {
                        const sel = picked.includes(c.country);

                        return (
                            <li key={c.country}>
                                <button type="button" onClick={() => toggle(c.country)} aria-pressed={sel}
                                    className={cn('flex w-full items-center gap-2.5 rounded-lg border px-3 py-2 text-left text-sm transition-colors', sel ? 'border-foreground bg-foreground/[0.04]' : 'border-transparent hover:bg-accent')}>
                                    <span className={cn('flex size-4 shrink-0 items-center justify-center rounded border', sel ? 'border-foreground bg-foreground text-background' : 'border-muted-foreground/50')}>{sel && <Check className="size-3" strokeWidth={3} />}</span>
                                    <span aria-hidden>{c.flag}</span>
                                    <span className="min-w-0 flex-1 [overflow-wrap:anywhere]">{c.name}</span>
                                    <span className="shrink-0 text-[11px] text-muted-foreground">{c.currency}</span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
                <p className="mt-3 text-xs text-muted-foreground">Ticket payments are still taken in MYR through CHIP, whichever region an event is in.</p>
            </div>
        </div>
    );
}
