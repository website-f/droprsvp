import { router, usePage } from '@inertiajs/react';
import { CalendarDays, Flame, MapPin, Search, Tag } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

interface City { name: string; slug: string }
interface Suggestion { label: string; url: string }
interface Suggestions { hot: Suggestion[]; events: Suggestion[]; categories: Suggestion[]; cities: Suggestion[] }
type Group = 'hot' | 'event' | 'category' | 'city';

const GROUP_LABEL: Record<Group, string> = { hot: 'Trending', event: 'Events', category: 'Categories', city: 'Cities' };
const GROUP_ICON: Record<Group, typeof Flame> = { hot: Flame, event: CalendarDays, category: Tag, city: MapPin };
const EMPTY: Suggestions = { hot: [], events: [], categories: [], cities: [] };

/**
 * Eventbrite-style header search: an event query (with live autocomplete
 * suggestions from /search/suggest — trending + event/category/city matches) plus
 * a location, submitting to the discover page (`/en-my/{city}?q=…`). The location
 * uses our themed Select (not a native <select>). Responsive — stacks on mobile.
 */
export function HeaderSearch({ className = '' }: { className?: string }) {
    const cities = ((usePage().props as { cities?: City[] }).cities ?? []) as City[];
    const [q, setQ] = useState('');
    const [city, setCity] = useState('all');
    const [open, setOpen] = useState(false);
    const [sug, setSug] = useState<Suggestions>(EMPTY);
    const [active, setActive] = useState(-1);
    const boxRef = useRef<HTMLDivElement>(null);

    // Debounced suggestions fetch whenever the box is open and the query changes.
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
                /* ignore network hiccups */
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
    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (active >= 0 && flat[active]) {
            return go(flat[active].url);
        }

        setOpen(false);
        const base = city === 'all' ? '/en-my/all' : `/en-my/${city}`;
        router.get(base, q.trim() ? { q: q.trim() } : {});
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

    return (
        <div ref={boxRef} className={`relative ${className}`}>
            <form onSubmit={submit} className="flex w-full items-stretch gap-2 sm:gap-0 sm:rounded-full sm:border sm:border-border sm:bg-card sm:pl-2 sm:pr-1 sm:shadow-sm">
                {/* Query */}
                <label className="flex h-11 flex-1 items-center gap-2 rounded-full border border-border bg-card px-4 sm:h-12 sm:border-0 sm:bg-transparent sm:px-2">
                    <Search className="size-4 shrink-0 text-muted-foreground" />
                    <input
                        value={q}
                        onChange={(e) => {
 setQ(e.target.value); setOpen(true); 
}}
                        onFocus={() => setOpen(true)}
                        onKeyDown={onKey}
                        placeholder="Search events"
                        aria-label="Search events"
                        className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    />
                </label>

                {/* Location */}
                <div className="flex items-center gap-2 sm:border-l sm:border-border sm:pl-3">
                    <Select value={city} onValueChange={setCity}>
                        <SelectTrigger
                            aria-label="Location"
                            className="h-11 flex-1 gap-2 rounded-full border-border bg-card px-4 shadow-none sm:h-12 sm:min-w-[9rem] sm:border-0 sm:bg-transparent sm:px-1 sm:shadow-none dark:bg-card sm:dark:bg-transparent"
                        >
                            <MapPin className="size-4 shrink-0 text-muted-foreground" />
                            <SelectValue placeholder="Anywhere" />
                        </SelectTrigger>
                        <SelectContent className="max-h-72">
                            <SelectItem value="all">Anywhere</SelectItem>
                            {cities.map((c) => <SelectItem key={c.slug} value={c.slug}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <button type="submit" aria-label="Search" className="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-colors hover:bg-primary/90 sm:size-10">
                        <Search className="size-4" />
                    </button>
                </div>
            </form>

            {/* Autocomplete suggestions */}
            {open && flat.length > 0 && (
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
            )}
        </div>
    );
}
