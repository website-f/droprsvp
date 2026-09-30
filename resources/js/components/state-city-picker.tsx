import { useMemo, useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Label } from '@/components/ui/label';

export interface CityOption { name: string; slug: string; state: string }

const field = 'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/**
 * State (negeri), then city (daerah).
 *
 * City was a free-text box, which produced "KL", "kuala lumpur", "K.L." and
 * "Kajang " for what are four spellings of two places — and the city is what
 * the browse pages, the nearby-city chips and the organizer's own audience
 * breakdown are all keyed on. Picking from a list makes those agree.
 *
 * Only the CITY is stored. The state is derived from it on the way back in, so
 * this adds no column and no migration; it is a way of narrowing a long list,
 * not a second piece of data to keep in sync.
 *
 * Outside Malaysia the pair collapses back to a free-text box, because the
 * list is Malaysian and a Singaporean address should not have to pick a Malaysian
 * state to be typed in.
 */
export function StateCityPicker({
    cities,
    value,
    onChange,
    country = 'Malaysia',
    required = false,
    error,
    idPrefix = 'city',
}: {
    cities: CityOption[];
    /** The stored city name. */
    value: string;
    onChange: (city: string) => void;
    country?: string;
    required?: boolean;
    error?: string;
    idPrefix?: string;
}) {
    const states = useMemo(() => [...new Set(cities.map((c) => c.state))], [cities]);

    // Seeded from the stored city, so re-opening a saved profile lands on the
    // right state instead of an empty box.
    const [state, setState] = useState(() => cities.find((c) => c.name === value)?.state ?? '');

    const inMalaysia = country === 'Malaysia';
    const forState = state ? cities.filter((c) => c.state === state) : [];

    const mark = (label: string) => (
        <>
            {label}
            {required && <span className="text-destructive"> *</span>}
        </>
    );

    if (!inMalaysia) {
        return (
            <div className="grid gap-1.5">
                <Label htmlFor={idPrefix}>{mark('City')}</Label>
                <input
                    id={idPrefix}
                    className={field}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="e.g. Singapore"
                />
                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>
        );
    }

    return (
        <>
            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-state`}>{mark('State (Negeri)')}</Label>
                <AppSelect
                    id={`${idPrefix}-state`}
                    value={state}
                    placeholder="Select a state…"
                    onChange={(v) => {
                        setState(v);

                        // The stored city belongs to the old state, so keep it
                        // only if it also exists in the new one. Leaving it
                        // would save a city the picker no longer shows.
                        if (!cities.some((c) => c.state === v && c.name === value)) {
                            onChange('');
                        }
                    }}
                    options={states.map((s) => ({ value: s, label: s }))}
                />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-city`}>{mark('City / District (Daerah)')}</Label>
                <AppSelect
                    id={`${idPrefix}-city`}
                    value={value}
                    disabled={!state}
                    placeholder={state ? 'Select a city…' : 'Pick a state first'}
                    onChange={onChange}
                    options={forState.map((c) => ({ value: c.name, label: c.name }))}
                />
                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>
        </>
    );
}
