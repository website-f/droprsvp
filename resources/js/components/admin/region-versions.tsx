import { Link, router } from '@inertiajs/react';
import { Check, Globe, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export interface RegionOption { code: string; name: string; flag: string; default?: boolean }
export interface VersionTab { id: number; label: string; flags: string; regions: string[]; status: string | null; main: boolean; active: boolean }

/**
 * Tap regions on and off. Empty means "every region" when `allowAll`; a
 * region version needs at least one. `taken` regions (another version has
 * them) are shown but cannot be picked.
 */
export function RegionPicker({ options, value, onChange, allowAll = false, taken = [] }: {
    options: RegionOption[];
    value: string[];
    onChange: (codes: string[]) => void;
    allowAll?: boolean;
    taken?: string[];
}) {
    const toggle = (code: string) => onChange(value.includes(code) ? value.filter((c) => c !== code) : [...value, code]);

    return (
        <div className="grid gap-2">
            {allowAll && (
                <button type="button" onClick={() => onChange([])}
                    className={cn('flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition-colors', value.length === 0 ? 'border-foreground bg-foreground/[0.04]' : 'border-border hover:bg-accent')}>
                    <Globe className="size-4 shrink-0" />
                    <span className="flex-1">All regions</span>
                    {value.length === 0 && <Check className="size-4" />}
                </button>
            )}
            <div className="flex flex-wrap gap-1.5">
                {options.map((r) => {
                    const on = value.includes(r.code);
                    const blocked = !on && taken.includes(r.code);

                    return (
                        <button key={r.code} type="button" disabled={blocked} onClick={() => toggle(r.code)} aria-pressed={on}
                            title={blocked ? `${r.name} already has its own version` : r.name}
                            className={cn('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40',
                                on ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40')}>
                            <span aria-hidden>{r.flag}</span> {r.name}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * The tabs above a page / help article / legal page editor — the main
 * version, then one per region version — and "Add a region version", which
 * copies the main one for the regions picked.
 */
export function RegionVersionTabs({ tabs, editHref, addUrl, options }: {
    tabs: VersionTab[];
    editHref: (id: number) => string;
    /** POST here with { regions } to create a version. */
    addUrl: string;
    options: RegionOption[];
}) {
    const [open, setOpen] = useState(false);
    const [picked, setPicked] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const taken = tabs.filter((t) => !t.main).flatMap((t) => t.regions);

    if (options.length < 2) {
        return null;
    }

    const create = () => {
        setBusy(true);
        setError(null);
        router.post(addUrl, { regions: picked }, {
            onError: (e) => setError(Object.values(e)[0] ?? 'Could not create the version.'),
            onFinish: () => setBusy(false),
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <div className="rounded-xl border border-border bg-card p-2">
            <div className="flex flex-wrap items-center gap-1">
                <span className="flex items-center gap-1.5 px-2 text-xs font-medium uppercase tracking-wide text-muted-foreground"><Globe className="size-3.5" /> Region versions</span>
                {tabs.map((t) => (
                    <Link key={t.id} href={editHref(t.id)} preserveScroll
                        className={cn('inline-flex max-w-full items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm transition-colors', t.active ? 'bg-foreground text-background' : 'hover:bg-accent')}>
                        {t.flags && <span aria-hidden>{t.flags}</span>}
                        <span className="truncate">{t.label}</span>
                        {t.status === 'draft' && <span className={cn('rounded px-1 text-[10px] uppercase', t.active ? 'bg-background/20' : 'bg-muted text-muted-foreground')}>draft</span>}
                    </Link>
                ))}
                <button type="button" onClick={() => {
 setPicked([]); setError(null); setOpen(true);
}}
                    className="inline-flex items-center gap-1 rounded-lg border border-dashed border-border px-3 py-1.5 text-sm text-muted-foreground hover:border-foreground/40 hover:text-foreground">
                    <Plus className="size-3.5" /> Add a region version
                </button>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Add a region version</DialogTitle>
                        <DialogDescription>
                            Visitors in the regions you pick see this version instead of the main one — same address, its own content and SEO.
                            It starts as a copy of the main version, as a draft.
                        </DialogDescription>
                    </DialogHeader>
                    <RegionPicker options={options} value={picked} onChange={setPicked} taken={taken} />
                    {error && <p className="text-xs text-destructive">{error}</p>}
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button disabled={picked.length === 0 || busy} onClick={create}>Create version</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export interface RegionSettingTab { code: string | null; name: string; flag: string; customised: boolean; active: boolean }

/**
 * Country tabs for a settings screen (SEO and the like): the default for
 * every region, then one per region holding only what that country changes.
 */
export function RegionSettingTabs({ tabs, href }: { tabs: RegionSettingTab[]; href: (code: string | null) => string }) {
    if (tabs.length < 2) {
        return null;
    }

    const active = tabs.find((t) => t.active);

    return (
        <div className="mb-5 rounded-xl border border-border bg-card p-2">
            <div className="flex flex-wrap items-center gap-1">
                <span className="flex items-center gap-1.5 px-2 text-xs font-medium uppercase tracking-wide text-muted-foreground"><Globe className="size-3.5" /> Region</span>
                {tabs.map((t) => (
                    <Link key={t.code ?? 'default'} href={href(t.code)} preserveScroll
                        className={cn('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm transition-colors', t.active ? 'bg-foreground text-background' : 'hover:bg-accent')}>
                        <span aria-hidden>{t.flag}</span> {t.name}
                        {t.customised && <span className={cn('size-1.5 rounded-full', t.active ? 'bg-background' : 'bg-primary')} title="Has its own settings" />}
                    </Link>
                ))}
            </div>
            {active?.code && (
                <p className="px-2 pb-1 pt-2 text-xs text-muted-foreground">
                    Only what you fill in here changes for {active.name}. Blank fields use the default, shown greyed out.
                </p>
            )}
        </div>
    );
}
