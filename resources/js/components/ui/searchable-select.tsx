import { Check, ChevronsUpDown, Search, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export interface SearchableOption { value: string; label: string; hint?: string }

/**
 * A select you can type into.
 *
 * The plain dropdown is fine for a handful of options and unusable for four
 * hundred — which is where the finance event filter is heading. Typing filters
 * the list, so finding one event is one query rather than a scroll.
 *
 * Filtering is done here rather than on the server: the whole list is already
 * on the page, and a round trip per keystroke would be slower and would break
 * while offline. If the list ever outgrows that, `truncated` says so out loud
 * instead of silently hiding the option someone is looking for.
 */
export function SearchableSelect({
    value,
    onChange,
    options,
    placeholder = 'Select…',
    searchPlaceholder = 'Type to search…',
    truncated = false,
    id,
    className,
    'aria-label': ariaLabel,
}: {
    value: string;
    onChange: (value: string) => void;
    options: SearchableOption[];
    placeholder?: string;
    searchPlaceholder?: string;
    /** True when the server sent only part of the list. */
    truncated?: boolean;
    id?: string;
    className?: string;
    'aria-label'?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const root = useRef<HTMLDivElement>(null);
    const field = useRef<HTMLInputElement>(null);
    const list = useRef<HTMLDivElement>(null);

    const selected = options.find((o) => o.value === value);

    const matches = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return options;
        }

        // Every word must appear somewhere, so "clock kajang" finds
        // "Blood on the Clocktower — Kajang" regardless of the order typed.
        const words = q.split(/\s+/);

        return options.filter((o) => {
            const haystack = `${o.label} ${o.hint ?? ''}`.toLowerCase();

            return words.every((w) => haystack.includes(w));
        });
    }, [options, query]);

    // Close on an outside click or Escape.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onDown = (e: MouseEvent) => {
            if (root.current && !root.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onDown);

        return () => document.removeEventListener('mousedown', onDown);
    }, [open]);

    useEffect(() => {
        if (open) {
            setQuery('');
            setActive(0);
            // Focus the box, not the trigger — the point is to start typing.
            requestAnimationFrame(() => field.current?.focus());
        }
    }, [open]);

    // Keep the highlighted row in view when moving with the keyboard.
    useEffect(() => {
        list.current?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const choose = (option: SearchableOption) => {
        onChange(option.value);
        setOpen(false);
    };

    const onKey = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => {
                const next = e.key === 'ArrowDown' ? i + 1 : i - 1;

                return Math.max(0, Math.min(matches.length - 1, next));
            });
        }

        if (e.key === 'Enter' && matches[active]) {
            e.preventDefault();
            choose(matches[active]);
        }

        if (e.key === 'Escape') {
            setOpen(false);
        }
    };

    return (
        <div ref={root} className={cn('relative', className)}>
            <button
                type="button"
                id={id}
                aria-label={ariaLabel}
                aria-expanded={open}
                aria-haspopup="listbox"
                onClick={() => setOpen((v) => !v)}
                className="flex h-10 w-full items-center gap-2 rounded-lg border border-input bg-card px-3 text-left text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
            >
                <span className={cn('min-w-0 flex-1 truncate', !selected && 'text-muted-foreground')}>
                    {selected?.label ?? placeholder}
                </span>
                <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
            </button>

            {open && (
                <div className="absolute z-50 mt-1 w-full overflow-hidden rounded-lg border border-border bg-popover shadow-lg">
                    <div className="flex items-center gap-2 border-b border-border px-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input
                            ref={field}
                            value={query}
                            onChange={(e) => { setQuery(e.target.value); setActive(0); }}
                            onKeyDown={onKey}
                            placeholder={searchPlaceholder}
                            className="h-10 w-full bg-transparent text-sm outline-none"
                        />
                        {query && (
                            <button type="button" onClick={() => setQuery('')} aria-label="Clear search" className="shrink-0 text-muted-foreground hover:text-foreground">
                                <X className="size-3.5" />
                            </button>
                        )}
                    </div>

                    <div ref={list} role="listbox" className="max-h-64 overflow-y-auto py-1">
                        {matches.length === 0 ? (
                            <p className="px-3 py-6 text-center text-xs text-muted-foreground">Nothing matches &ldquo;{query}&rdquo;.</p>
                        ) : (
                            matches.map((o, i) => (
                                <button
                                    key={o.value}
                                    type="button"
                                    role="option"
                                    aria-selected={o.value === value}
                                    data-active={i === active}
                                    onMouseEnter={() => setActive(i)}
                                    onClick={() => choose(o)}
                                    className={cn(
                                        'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                                        i === active && 'bg-accent',
                                    )}
                                >
                                    <Check className={cn('size-3.5 shrink-0', o.value === value ? 'opacity-100' : 'opacity-0')} />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate">{o.label}</span>
                                        {o.hint && <span className="block truncate text-xs text-muted-foreground">{o.hint}</span>}
                                    </span>
                                </button>
                            ))
                        )}
                    </div>

                    {truncated && (
                        <p className="border-t border-border px-3 py-2 text-[11px] text-muted-foreground">
                            Showing the most recent {options.length}. Narrow the date range to reach older ones.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
