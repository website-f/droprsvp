import { ChevronDown, Search, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CATEGORY_ICON_NAMES, CATEGORY_ICONS, categoryIcon } from '@/lib/category-icons';
import { cn } from '@/lib/utils';

/**
 * Searchable icon picker (type to autocomplete). Themed to match our inputs; the
 * panel is a self-contained absolute dropdown with outside-click close so it works
 * inside scrolling admin lists. Value is a category-icon name (see category-icons).
 */
export function IconPicker({
    value, onChange, color, className,
}: {
    value: string | null;
    onChange: (name: string | null) => void;
    color?: string | null;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, [open]);

    const results = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return CATEGORY_ICON_NAMES;
        return CATEGORY_ICON_NAMES.filter((n) => n.includes(q));
    }, [query]);

    const Current = categoryIcon(value);
    const accent = color || 'var(--foreground)';

    return (
        <div ref={ref} className={cn('relative', className)}>
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="flex h-10 w-full items-center gap-2 rounded-lg border border-input bg-card px-2.5 text-sm outline-none hover:bg-accent focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
            >
                <span
                    className="flex size-6 shrink-0 items-center justify-center rounded-md"
                    style={{ backgroundColor: value ? `${color ?? '#6c63ff'}1f` : 'var(--muted)', color: value ? accent : 'var(--muted-foreground)' }}
                >
                    <Current className="size-4" />
                </span>
                <span className="min-w-0 flex-1 truncate text-left text-muted-foreground">{value ?? 'Choose an icon…'}</span>
                {value && (
                    <span
                        role="button"
                        tabIndex={0}
                        aria-label="Clear icon"
                        onClick={(e) => { e.stopPropagation(); onChange(null); }}
                        className="flex size-5 items-center justify-center rounded text-muted-foreground hover:bg-accent"
                    >
                        <X className="size-3.5" />
                    </span>
                )}
                <ChevronDown className="size-4 shrink-0 opacity-50" />
            </button>

            {open && (
                <div className="absolute left-0 top-full z-50 mt-1 w-full min-w-[16rem] overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
                    <div className="flex items-center gap-2 border-b border-border px-3 py-2">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input
                            autoFocus
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search icons — music, food, sport…"
                            className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        />
                    </div>
                    <div className="grid max-h-56 grid-cols-6 gap-1 overflow-y-auto p-2">
                        {results.length === 0 ? (
                            <p className="col-span-6 py-6 text-center text-xs text-muted-foreground">No icons match “{query}”.</p>
                        ) : (
                            results.map((name) => {
                                const Icon = CATEGORY_ICONS[name];
                                const active = name === value;
                                return (
                                    <button
                                        key={name}
                                        type="button"
                                        title={name}
                                        onClick={() => { onChange(name); setOpen(false); setQuery(''); }}
                                        className={cn(
                                            'flex aspect-square items-center justify-center rounded-lg border transition-colors',
                                            active ? 'border-ring bg-accent' : 'border-transparent hover:bg-accent',
                                        )}
                                        style={active && color ? { color } : undefined}
                                    >
                                        <Icon className="size-4" />
                                    </button>
                                );
                            })
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
