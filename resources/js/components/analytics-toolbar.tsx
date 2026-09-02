import { router } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { useState } from 'react';

export interface AnalyticsPeriod { period: string; from: string; to: string; periodLabel?: string }

const PRESETS: [string, string][] = [['7d', '7D'], ['30d', '30D'], ['90d', '90D'], ['12m', '12M']];
const dateInput = 'h-9 rounded-lg border border-input bg-card px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/**
 * Date-range filter shared by every analytics page. Preset chips (7D/30D/90D/12M)
 * plus a Custom range; selecting one navigates with the new query params while
 * preserving any other filters passed in `extra` (search, status, sort…).
 */
export function AnalyticsToolbar({ path, filters, extra = {} }: { path: string; filters: AnalyticsPeriod; extra?: Record<string, string> }) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const isCustom = filters.period === 'custom';

    const go = (params: Record<string, string | undefined>) => {
        const clean: Record<string, string> = { ...extra };

        for (const [k, v] of Object.entries(params)) {
            if (v) {
                clean[k] = v;
            }
        }

        router.get(path, clean, { preserveScroll: true, preserveState: true });
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="inline-flex rounded-lg border border-border p-0.5">
                {PRESETS.map(([val, label]) => (
                    <button key={val} type="button" onClick={() => go({ period: val })} className={`rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${filters.period === val ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'}`}>{label}</button>
                ))}
                <button type="button" onClick={() => go({ period: 'custom', from: from || filters.from, to: to || filters.to })} className={`flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${isCustom ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'}`}><CalendarRange className="size-3.5" /> Custom</button>
            </div>
            {isCustom && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <input type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} className={dateInput} aria-label="From date" />
                    <span className="text-xs text-muted-foreground">to</span>
                    <input type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} className={dateInput} aria-label="To date" />
                    <button type="button" onClick={() => go({ period: 'custom', from, to })} disabled={!from || !to} className="h-9 rounded-lg bg-foreground px-3 text-xs font-medium text-background disabled:opacity-50">Apply</button>
                </div>
            )}
        </div>
    );
}
