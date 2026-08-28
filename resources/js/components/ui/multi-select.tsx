import { ChevronDown, X } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

interface Option { value: string; label: string }

/**
 * Themed multi-select built on the Radix dropdown (checkbox items) — matches
 * the DropRSVP design system, no jQuery/select2. Selecting keeps the menu open;
 * chosen values show as removable chips in the trigger.
 */
export function MultiSelect({
    values,
    onChange,
    options,
    placeholder = 'Select…',
    id,
}: {
    values: string[];
    onChange: (values: string[]) => void;
    options: Option[];
    placeholder?: string;
    id?: string;
}) {
    const selected = options.filter((o) => values.includes(o.value));

    const toggle = (value: string) =>
        onChange(values.includes(value) ? values.filter((v) => v !== value) : [...values, value]);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    id={id}
                    type="button"
                    className="flex min-h-10 w-full items-center justify-between gap-2 rounded-lg border border-input bg-background px-3 py-1.5 text-left text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                >
                    <span className="flex flex-1 flex-wrap gap-1.5">
                        {selected.length === 0 && <span className="py-1 text-muted-foreground">{placeholder}</span>}
                        {selected.map((o) => (
                            <span key={o.value} className="inline-flex items-center gap-1 rounded-md bg-secondary px-2 py-0.5 text-xs font-medium">
                                {o.label}
                                <span
                                    role="button"
                                    tabIndex={-1}
                                    aria-label={`Remove ${o.label}`}
                                    onClick={(e) => { e.stopPropagation(); toggle(o.value); }}
                                    className="rounded-sm text-muted-foreground hover:text-foreground"
                                >
                                    <X className="size-3" />
                                </span>
                            </span>
                        ))}
                    </span>
                    <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="max-h-72 w-[var(--radix-dropdown-menu-trigger-width)] overflow-y-auto">
                {options.map((o) => (
                    <DropdownMenuCheckboxItem
                        key={o.value}
                        checked={values.includes(o.value)}
                        onSelect={(e) => e.preventDefault()}
                        onCheckedChange={() => toggle(o.value)}
                    >
                        {o.label}
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
