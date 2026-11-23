import { router, usePage } from '@inertiajs/react';
import { Check, Globe, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export interface RegionOption { code: string; name: string; flag: string; country: string; default?: boolean; zones?: string[] }

/** Set once the region has been chosen — by hand, or detected on a first visit. */
const CHOSEN = 'drsvp_region_chosen';
const remember = () => {
    try {
        localStorage.setItem(CHOSEN, '1');
    } catch {
        /* private mode: detection may run again, which is harmless */
    }
};

/** Pages that exist the same in every region — switching keeps you on them. */
const SAME_IN_EVERY_REGION = ['all', 'blog', 'help', 'contact'];

/**
 * Where a region switch should land: the same page in the new region when it
 * is one every region has (browse, blog, help, contact); otherwise the new
 * region's home — an event or a city belongs to one region only.
 */
export function regionHref(code: string): string {
    if (typeof window === 'undefined') {
        return `/${code}/`;
    }

    const [, first, second, ...rest] = window.location.pathname.split('/');

    if (/^[a-z]{2}-[a-z]{2}$/.test(first ?? '') && SAME_IN_EVERY_REGION.includes(second ?? '')) {
        const tail = second === 'all' ? '' : rest.filter(Boolean).join('/');

        return `/${code}/${second}/${tail ? `${tail}/` : ''}${second === 'all' ? window.location.search : ''}`;
    }

    return `/${code}/`;
}

/**
 * The region picker in the public header: a flag button that opens a
 * searchable list of the regions the site runs in. Hidden when only one is on.
 */
export function RegionSwitcher({ className, label = false }: { className?: string; label?: boolean }) {
    const { region, regions = [] } = usePage().props as { region?: RegionOption; regions?: RegionOption[] };
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');

    const list = useMemo(() => {
        const term = q.trim().toLowerCase();

        return term ? regions.filter((r) => r.name.toLowerCase().includes(term) || r.code.includes(term) || r.country.toLowerCase() === term) : regions;
    }, [q, regions]);

    if (!region || regions.length < 2) {
        return null;
    }

    const pick = (code: string) => {
        setOpen(false);
        remember();

        if (code !== region.code) {
            router.visit(regionHref(code));
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => {
                    setQ('');
                    setOpen(true);
                }}
                aria-label={`Region: ${region.name}. Change region`}
                title={`${region.name} — change region`}
                className={cn('flex h-10 items-center gap-1.5 rounded-lg border border-border px-2.5 text-sm font-medium transition-colors hover:bg-accent', className)}
            >
                <span className="text-base leading-none" aria-hidden>{region.flag}</span>
                <span className={label ? '' : 'hidden lg:inline'}>{label ? region.name : region.country}</span>
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[85vh] overflow-hidden p-0 sm:max-w-md">
                    <DialogHeader className="px-5 pt-5">
                        <DialogTitle className="flex items-center gap-2"><Globe className="size-4" /> Choose your region</DialogTitle>
                        <DialogDescription>You’ll see events happening there.</DialogDescription>
                    </DialogHeader>
                    {regions.length > 8 && (
                        <div className="relative px-5">
                            <Search className="pointer-events-none absolute top-1/2 left-8 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                autoFocus
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Search a country"
                                aria-label="Search regions"
                                className="h-10 w-full rounded-lg border border-input bg-card pr-3 pl-9 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                            />
                        </div>
                    )}
                    <ul className="max-h-[55vh] overflow-y-auto px-2 pb-3" role="listbox" aria-label="Regions">
                        {list.length === 0 && <li className="px-3 py-6 text-center text-sm text-muted-foreground">No region matches.</li>}
                        {list.map((r) => (
                            <li key={r.code}>
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={r.code === region.code}
                                    onClick={() => pick(r.code)}
                                    className={cn('flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm transition-colors hover:bg-accent', r.code === region.code && 'bg-accent')}
                                >
                                    <span className="text-lg leading-none" aria-hidden>{r.flag}</span>
                                    <span className="min-w-0 flex-1">{r.name}</span>
                                    {r.code === region.code && <Check className="size-4 shrink-0" />}
                                </button>
                            </li>
                        ))}
                    </ul>
                </DialogContent>
            </Dialog>
        </>
    );
}

/**
 * Detect the visitor's region on their first visit to a home or browse page:
 * the region whose country their device's clock is in, if that region is on.
 * Switches there once, says so, and never again — a region picked by hand
 * (or this one, once made) is kept. Event, blog and other pages are left
 * alone: whoever followed a link there wants that page, wherever they are.
 */
export function RegionAutoDetect() {
    const { region, regions = [] } = usePage().props as { region?: RegionOption; regions?: RegionOption[] };

    useEffect(() => {
        if (!region || regions.length < 2) {
            return;
        }

        try {
            if (localStorage.getItem(CHOSEN)) {
                return;
            }
        } catch {
            return;
        }

        const [, first, second] = window.location.pathname.split('/');

        // Only the generic landing — the default region's home or events
        // page — adapts. Someone who followed a link to /en-sg/ meant it.
        if (!region.default || first !== region.code || (second && second !== 'all')) {
            return;
        }

        let zone = '';

        try {
            zone = Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
        } catch {
            /* no Intl */
        }

        remember();
        const here = regions.find((r) => r.zones?.includes(zone));

        if (here && here.code !== region.code) {
            toast.info(`Showing events in ${here.name}`, { description: 'Change the region any time from the flag in the header.' });
            router.visit(regionHref(here.code), { replace: true });
        }
    }, [region, regions]);

    return null;
}
