import { useForm } from '@inertiajs/react';
import { Globe, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { CatalogCountry } from '@/components/admin/regions-settings';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { currencySymbol } from '@/lib/money';
import { cn } from '@/lib/utils';

/** One layer of rates. Blank = inherit from the layer below. */
export interface Rate { fee_percent?: string | number; fee_flat?: string | number; tax_percent?: string | number; tax_label?: string; currency?: string; gateway?: string }
interface Defaults { fee_percent: number | string; fee_flat: number | string; tax_percent: number | string; tax_label: string }

const input = 'h-9 w-full rounded-lg border border-input bg-card px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const GATEWAYS = [
    { value: 'auto', label: 'Automatic' },
    { value: 'airwallex', label: 'Airwallex' },
    { value: 'chip', label: 'CHIP (ringgit only)' },
];

const str = (v: unknown) => (v === undefined || v === null ? '' : String(v));

/**
 * The editor for one layer of rates: platform fee (% or flat), tax and its
 * label, currency, and gateway. Placeholders show what applies when blank.
 */
function RateFields({ value, onChange, inherit, country }: { value: Rate; onChange: (v: Rate) => void; inherit: Defaults & { currency: string }; country?: CatalogCountry }) {
    const set = (k: keyof Rate, v: string) => onChange({ ...value, [k]: v });
    const malaysia = country?.country === 'MY';
    const currency = (str(value.currency) || inherit.currency).toUpperCase();

    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-6">
            <label className="grid gap-1 text-xs text-muted-foreground">Fee %
                <input type="number" min={0} max={100} step="0.1" className={input} value={str(value.fee_percent)} placeholder={str(inherit.fee_percent)} onChange={(e) => set('fee_percent', e.target.value)} />
            </label>
            <label className="grid gap-1 text-xs text-muted-foreground">or flat ({currencySymbol(currency)})
                <input type="number" min={0} step="0.5" className={input} value={str(value.fee_flat)} placeholder={str(inherit.fee_flat)} onChange={(e) => set('fee_flat', e.target.value)} />
            </label>
            <label className="grid gap-1 text-xs text-muted-foreground">Tax %
                <input type="number" min={0} max={100} step="0.1" className={input} value={str(value.tax_percent)} placeholder={str(inherit.tax_percent)} onChange={(e) => set('tax_percent', e.target.value)} />
            </label>
            <label className="grid gap-1 text-xs text-muted-foreground">Tax label
                <input className={input} maxLength={20} value={str(value.tax_label)} placeholder={inherit.tax_label || 'Tax'} onChange={(e) => set('tax_label', e.target.value)} />
            </label>
            <label className="grid gap-1 text-xs text-muted-foreground">Currency
                <input className={`${input} uppercase`} maxLength={3} disabled={malaysia} value={str(value.currency)} placeholder={inherit.currency} onChange={(e) => set('currency', e.target.value.toUpperCase())} />
            </label>
            <div className="grid gap-1 text-xs text-muted-foreground">Gateway
                {malaysia
                    ? <div className="flex h-9 items-center rounded-lg border border-border px-2.5 text-sm text-muted-foreground">CHIP</div>
                    : <AppSelect value={str(value.gateway) || 'auto'} onChange={(v) => set('gateway', v === 'auto' ? '' : v)} options={GATEWAYS} className="h-9 text-sm" />}
            </div>
        </div>
    );
}

/**
 * Settings → Country rates. Malaysia (and anywhere not listed) uses the
 * defaults on the Payments & fees and Tax tabs; "All other countries" covers
 * everywhere abroad in one go; a country of its own overrides both. Several
 * countries can be added — and given the same rates — at once.
 */
export function CountryRatesSettings({ initial, catalog, defaults, airwallexOn }: {
    initial: { foreign: Rate; countries: Record<string, Rate> };
    catalog: CatalogCountry[];
    defaults: Defaults;
    airwallexOn: boolean;
}) {
    const form = useForm<{ foreign: Rate; countries: Record<string, Rate> }>({
        foreign: initial.foreign ?? {},
        countries: Array.isArray(initial.countries) ? {} : (initial.countries ?? {}),
    });
    const { data, setData, processing, errors } = form;
    const byCode = useMemo(() => Object.fromEntries(catalog.map((c) => [c.country, c])), [catalog]);
    const [adding, setAdding] = useState(false);
    const [q, setQ] = useState('');
    const [picked, setPicked] = useState<string[]>([]);
    const [preset, setPreset] = useState<Rate>({});
    const [selected, setSelected] = useState<string[]>([]);
    const [bulk, setBulk] = useState<Rate | null>(null);

    // What a country inherits when a field is blank: the foreign layer, then the defaults.
    const localCurrency = (code: string) => (airwallexOn && code !== 'MY' ? byCode[code]?.currency ?? 'MYR' : 'MYR');
    const inheritFor = (code?: string) => ({
        fee_percent: str(data.foreign.fee_percent) || str(defaults.fee_percent),
        fee_flat: str(data.foreign.fee_flat) || str(defaults.fee_flat),
        tax_percent: str(data.foreign.tax_percent) || str(defaults.tax_percent),
        tax_label: str(data.foreign.tax_label) || defaults.tax_label,
        currency: code ? (str(data.foreign.currency) || localCurrency(code)) : (airwallexOn ? 'Local' : 'MYR'),
    });

    const codes = Object.keys(data.countries).sort((a, b) => (byCode[a]?.name ?? a).localeCompare(byCode[b]?.name ?? b));
    const matches = catalog.filter((c) => !data.countries[c.country] && (`${c.name} ${c.country} ${c.area}`).toLowerCase().includes(q.trim().toLowerCase()));

    const addPicked = () => {
        const next = { ...data.countries };
        picked.forEach((code) => {
            next[code] = { ...preset };
        });
        setData('countries', next);
        setAdding(false);
        setPicked([]);
        setPreset({});
        setQ('');
    };

    const applyBulk = () => {
        if (!bulk) {
            return;
        }

        const next = { ...data.countries };
        selected.forEach((code) => {
            // Only the fields filled in the bulk editor change; the rest stay.
            next[code] = { ...next[code], ...Object.fromEntries(Object.entries(bulk).filter(([, v]) => str(v) !== '')) };
        });
        setData('countries', next);
        setBulk(null);
        setSelected([]);
    };

    const remove = (code: string) => {
        const next = { ...data.countries };
        delete next[code];
        setData('countries', next);
        setSelected(selected.filter((c) => c !== code));
    };

    const errorList = Object.values(errors as Record<string, string>);

    return (
        <div className="grid gap-5">
            <p className="text-sm text-muted-foreground">
                Malaysia uses the defaults on <strong>Payments &amp; fees</strong> and <strong>Tax</strong>. Set different rates for other countries here — for all of them at once, or country by country.
                A blank field uses the rate shown greyed out. Events are charged at the rates of the country they’re in; an organizer on a custom fee keeps it everywhere.
            </p>
            {!airwallexOn && (
                <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs">
                    Airwallex isn’t set up yet, so every country pays through CHIP in ringgit. Once it’s on (Airwallex tab), countries outside Malaysia are charged in their own currency.
                </p>
            )}
            {errorList.length > 0 && <div className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{[...new Set(errorList)].map((e) => <p key={e}>{e}</p>)}</div>}

            <div className="rounded-xl border border-border p-4">
                <div className="mb-3 flex items-center gap-2 text-sm font-semibold"><Globe className="size-4" /> All other countries</div>
                <RateFields value={data.foreign} onChange={(v) => setData('foreign', v)}
                    inherit={{ ...defaults, currency: airwallexOn ? 'Local' : 'MYR' }} />
                <p className="mt-2 text-xs text-muted-foreground">Everywhere outside Malaysia, unless a country has rates of its own below. Leave the currency blank to charge each country in its own.</p>
            </div>

            <div className="rounded-xl border border-border">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border p-3">
                    <div className="text-sm font-semibold">Countries with their own rates <span className="font-normal text-muted-foreground">({codes.length})</span></div>
                    <div className="flex flex-wrap gap-2">
                        {selected.length > 0 && <Button type="button" size="sm" variant="outline" onClick={() => setBulk({})}>Set rates for {selected.length} selected</Button>}
                        <Button type="button" size="sm" onClick={() => setAdding(true)}><Plus className="size-3.5" /> Add countries</Button>
                    </div>
                </div>
                {codes.length === 0 ? (
                    <p className="p-6 text-center text-sm text-muted-foreground">None yet. Add one country, or pick several and give them the same rates.</p>
                ) : (
                    <ul className="divide-y divide-border">
                        {codes.length > 1 && (
                            <li className="flex items-center gap-2 px-3 py-2 text-xs text-muted-foreground">
                                <input type="checkbox" aria-label="Select all" checked={selected.length === codes.length} onChange={(e) => setSelected(e.target.checked ? codes : [])} />
                                Select all
                            </li>
                        )}
                        {codes.map((code) => (
                            <li key={code} className="grid gap-2 p-3">
                                <div className="flex items-center gap-2">
                                    <input type="checkbox" aria-label={`Select ${byCode[code]?.name ?? code}`} checked={selected.includes(code)}
                                        onChange={(e) => setSelected(e.target.checked ? [...selected, code] : selected.filter((c) => c !== code))} />
                                    <span aria-hidden>{byCode[code]?.flag}</span>
                                    <span className="flex-1 text-sm font-medium">{byCode[code]?.name ?? code}</span>
                                    <Button type="button" variant="ghost" size="icon" aria-label={`Remove ${byCode[code]?.name ?? code}`} onClick={() => remove(code)}><Trash2 className="size-4" /></Button>
                                </div>
                                <RateFields value={data.countries[code]} country={byCode[code]} inherit={inheritFor(code)}
                                    onChange={(v) => setData('countries', { ...data.countries, [code]: v })} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="flex justify-end">
                <Button type="button" disabled={processing} onClick={() => form.post('/admin/settings/country-rates', { preserveScroll: true })}>Save country rates</Button>
            </div>

            {/* Add one or many countries, optionally with the same starting rates. */}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Add countries</DialogTitle>
                        <DialogDescription>Pick one or several. Rates you fill in below are given to all of them — leave fields blank to inherit.</DialogDescription>
                    </DialogHeader>
                    <label className="flex h-9 items-center gap-2 rounded-lg border border-input px-2.5">
                        <Search className="size-4 text-muted-foreground" />
                        <input aria-label="Search countries" className="w-full bg-transparent text-sm outline-none" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search a country or area (e.g. Europe)…" />
                    </label>
                    <div className="flex flex-wrap gap-2 text-xs">
                        <button type="button" className="underline underline-offset-2" onClick={() => setPicked([...new Set([...picked, ...matches.map((c) => c.country)])])}>Select all {matches.length} shown</button>
                        {picked.length > 0 && <button type="button" className="underline underline-offset-2" onClick={() => setPicked([])}>Clear ({picked.length})</button>}
                    </div>
                    <div className="max-h-60 overflow-y-auto rounded-lg border border-border">
                        {matches.slice(0, 300).map((c) => (
                            <label key={c.country} className={cn('flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm hover:bg-accent', picked.includes(c.country) && 'bg-accent')}>
                                <input type="checkbox" checked={picked.includes(c.country)} onChange={(e) => setPicked(e.target.checked ? [...picked, c.country] : picked.filter((x) => x !== c.country))} />
                                <span aria-hidden>{c.flag}</span> <span className="flex-1">{c.name}</span>
                                <span className="text-xs text-muted-foreground">{c.currency} · {c.area}</span>
                            </label>
                        ))}
                        {matches.length === 0 && <p className="p-4 text-center text-sm text-muted-foreground">No countries match.</p>}
                    </div>
                    <div className="rounded-lg border border-border p-3">
                        <div className="mb-2 text-xs font-medium">Starting rates for the {picked.length || ''} countr{picked.length === 1 ? 'y' : 'ies'} picked</div>
                        <RateFields value={preset} onChange={setPreset} inherit={inheritFor(picked[0] ?? 'SG')} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setAdding(false)}>Cancel</Button>
                        <Button disabled={picked.length === 0} onClick={addPicked}>Add {picked.length || ''} countr{picked.length === 1 ? 'y' : 'ies'}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Same rates for every selected row. */}
            <Dialog open={bulk !== null} onOpenChange={(o) => !o && setBulk(null)}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Set rates for {selected.length} countr{selected.length === 1 ? 'y' : 'ies'}</DialogTitle>
                        <DialogDescription>Only the fields you fill in change; the rest keep each country’s own value.</DialogDescription>
                    </DialogHeader>
                    <p className="text-xs text-muted-foreground">{selected.map((c) => byCode[c]?.name ?? c).join(', ')}</p>
                    <RateFields value={bulk ?? {}} onChange={setBulk} inherit={inheritFor(selected[0] ?? 'SG')} />
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setBulk(null)}>Cancel</Button>
                        <Button onClick={applyBulk}>Apply to {selected.length}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
