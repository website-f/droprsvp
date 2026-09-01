import { Check, ChevronDown, Plus, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * A themed, select2-style single-select that also lets you CREATE a new value on
 * the fly: type a name, and if it doesn't match an existing option you can add it
 * (it's saved as the value; the backend auto-creates the category on save). Value
 * is the option's string. Self-contained dropdown with outside-click close.
 */
export function CreatableSelect({
    value, onChange, options, placeholder = 'Select or create…', id, className,
}: {
    value: string;
    onChange: (v: string) => void;
    options: string[];
    placeholder?: string;
    id?: string;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) { setOpen(false); setQuery(''); }
        };
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, [open]);

    const q = query.trim();
    const filtered = useMemo(() => {
        const needle = q.toLowerCase();
        return needle ? options.filter((o) => o.toLowerCase().includes(needle)) : options;
    }, [q, options]);
    const exact = options.some((o) => o.toLowerCase() === q.toLowerCase());

    const choose = (v: string) => { onChange(v); setOpen(false); setQuery(''); };

    return (
        <div ref={ref} className={cn('relative', className)}>
            <button
                type="button"
                id={id}
                onClick={() => setOpen((o) => !o)}
                className="flex h-10 w-full items-center gap-2 rounded-lg border border-input bg-background px-3 text-sm outline-none hover:bg-accent/50 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
            >
                <span className={cn('flex-1 truncate text-left', !value && 'text-muted-foreground')}>{value || placeholder}</span>
                {value && (
                    <span role="button" tabIndex={0} aria-label="Clear" onClick={(e) => { e.stopPropagation(); onChange(''); }} className="flex size-5 items-center justify-center rounded text-muted-foreground hover:bg-accent">
                        <X className="size-3.5" />
                    </span>
                )}
                <ChevronDown className="size-4 shrink-0 opacity-50" />
            </button>

            {open && (
                <div className="absolute left-0 top-full z-50 mt-1 w-full overflow-hidden rounded-xl border border-border bg-popover shadow-lg">
                    <div className="border-b border-border p-2">
                        <input
                            autoFocus
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search or type a new category…"
                            onKeyDown={(e) => { if (e.key === 'Enter' && q && !exact) { e.preventDefault(); choose(q); } }}
                            className="h-8 w-full rounded-md bg-transparent px-2 text-sm outline-none placeholder:text-muted-foreground"
                        />
                    </div>
                    <div className="max-h-56 overflow-y-auto p-1">
                        {filtered.map((o) => (
                            <button key={o} type="button" onClick={() => choose(o)} className="flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-2 text-left text-sm hover:bg-accent">
                                <span className="truncate">{o}</span>
                                {value === o && <Check className="size-4 shrink-0" />}
                            </button>
                        ))}
                        {q && !exact && (
                            <button type="button" onClick={() => choose(q)} className="flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-left text-sm font-medium text-primary hover:bg-accent">
                                <Plus className="size-4 shrink-0" /> Create “{q}”
                            </button>
                        )}
                        {filtered.length === 0 && !q && (
                            <p className="px-2.5 py-6 text-center text-xs text-muted-foreground">No categories yet — type to create one.</p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
