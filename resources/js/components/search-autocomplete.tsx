import { router } from '@inertiajs/react';
import { CalendarDays, Flame, MapPin, Search, Tag } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';

interface Suggestion { label: string; url: string; source?: string }
interface Suggestions { hot: Suggestion[]; events: Suggestion[]; categories: Suggestion[]; cities: Suggestion[] }
type Group = 'hot' | 'event' | 'category' | 'city';

const GROUP_LABEL: Record<Group, string> = { hot: 'Trending', event: 'Events', category: 'Categories', city: 'Cities' };
const GROUP_ICON: Record<Group, typeof Flame> = { hot: Flame, event: CalendarDays, category: Tag, city: MapPin };
const EMPTY: Suggestions = { hot: [], events: [], categories: [], cities: [] };

/**
 * Global search box with autocomplete. Suggestions come from /search/suggest —
 * admin "trending" + system-hot categories (fire icon) plus live event/category/
 * city matches. Enter (or the Search button) runs a full search.
 */
export function SearchAutocomplete({ variant = 'compact', initial = '', placeholder = 'Search events…', onSubmit }: {
    variant?: 'hero' | 'compact';
    initial?: string;
    placeholder?: string;
    onSubmit?: (q: string) => void;
}) {
    const [q, setQ] = useState(initial);
    const [open, setOpen] = useState(false);
    const [sug, setSug] = useState<Suggestions>(EMPTY);
    const [active, setActive] = useState(-1);
    const boxRef = useRef<HTMLDivElement>(null);

    // Debounced fetch whenever the box is open and the query changes.
    useEffect(() => {
        if (!open) {
            return;
        }

        const t = setTimeout(async () => {
            try {
                const res = await fetch(`/search/suggest?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });

                if (res.ok) {
                    setSug(await res.json());
                    setActive(-1);
                }
            } catch {
                /* ignore */
            }
        }, 160);

        return () => clearTimeout(t);
    }, [q, open]);

    // Close on outside click.
    useEffect(() => {
        const h = (e: MouseEvent) => {
            if (boxRef.current && !boxRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', h);

        return () => document.removeEventListener('mousedown', h);
    }, []);

    const flat: (Suggestion & { group: Group })[] = [
        ...sug.hot.map((s) => ({ ...s, group: 'hot' as const })),
        ...sug.events.map((s) => ({ ...s, group: 'event' as const })),
        ...sug.categories.map((s) => ({ ...s, group: 'category' as const })),
        ...sug.cities.map((s) => ({ ...s, group: 'city' as const })),
    ];

    const go = (url: string) => {
        setOpen(false);
        router.visit(url);
    };
    const runSearch = (e: React.FormEvent) => {
        e.preventDefault();

        if (active >= 0 && flat[active]) {
            return go(flat[active].url);
        }

        setOpen(false);
        const term = q.trim();

        if (onSubmit) {
            onSubmit(term);
        } else {
            router.get('/en-my/all', term ? { q: term } : {});
        }
    };
    const onKey = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((a) => Math.min(a + 1, flat.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((a) => Math.max(a - 1, -1));
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    };

    const dropdown = open && flat.length > 0 && (
        <div className="absolute left-0 right-0 top-[calc(100%+0.5rem)] z-50 overflow-hidden rounded-2xl border border-border bg-card py-1.5 text-left shadow-xl">
            {flat.map((item, i) => {
                const prev = flat[i - 1];
                const showHeader = !prev || prev.group !== item.group;
                const Icon = GROUP_ICON[item.group];

                return (
                    <Fragment key={`${item.group}-${item.label}-${i}`}>
                        {showHeader && <div className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{GROUP_LABEL[item.group]}</div>}
                        <button
                            type="button"
                            onMouseEnter={() => setActive(i)}
                            onMouseDown={(e) => {
 e.preventDefault(); go(item.url); 
}}
                            className={`flex w-full items-center gap-2.5 px-3 py-2 text-sm transition-colors ${active === i ? 'bg-accent' : 'hover:bg-accent/60'}`}
                        >
                            <Icon className={`size-4 shrink-0 ${item.group === 'hot' ? 'text-[#f5924a]' : 'text-muted-foreground'}`} />
                            <span className="truncate">{item.label}</span>
                        </button>
                    </Fragment>
                );
            })}
        </div>
    );

    if (variant === 'hero') {
        return (
            <div ref={boxRef}>
                <form onSubmit={runSearch} className="mx-auto flex w-full max-w-2xl flex-col gap-3 sm:flex-row">
                    <div className="relative flex-1">
                        <div className="flex h-16 items-center gap-3 rounded-2xl border border-border bg-card px-5 shadow-sm transition-colors focus-within:border-foreground/50 focus-within:ring-4 focus-within:ring-foreground/5 sm:rounded-full">
                            <Search className="size-5 shrink-0 text-muted-foreground" />
                            <input
                                value={q}
                                onChange={(e) => {
 setQ(e.target.value); setOpen(true); 
}}
                                onFocus={() => setOpen(true)}
                                onKeyDown={onKey}
                                className="h-full w-full bg-transparent text-base outline-none placeholder:text-muted-foreground"
                                placeholder={placeholder}
                                aria-label="Search events"
                            />
                        </div>
                        {dropdown}
                    </div>
                    <Button type="submit" size="lg" className="h-16 shrink-0 text-base sm:px-10">Search</Button>
                </form>
            </div>
        );
    }

    return (
        <div ref={boxRef} className="flex flex-1 gap-2">
            <form onSubmit={runSearch} className="flex flex-1 gap-2">
                <div className="relative flex-1">
                    <label className="flex h-11 items-center gap-2 rounded-full border border-border bg-card px-4">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input
                            className="w-full bg-transparent text-sm outline-none"
                            value={q}
                            onChange={(e) => {
 setQ(e.target.value); setOpen(true); 
}}
                            onFocus={() => setOpen(true)}
                            onKeyDown={onKey}
                            placeholder={placeholder}
                            aria-label="Search events"
                        />
                    </label>
                    {dropdown}
                </div>
                <Button type="submit" className="h-11">Search</Button>
            </form>
        </div>
    );
}
