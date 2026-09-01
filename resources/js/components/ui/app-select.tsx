import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';

export interface AppSelectOption { value: string; label: string; disabled?: boolean }

/**
 * Themed, responsive replacement for a native <select> (Radix under the hood,
 * so it inherits our custom scrollbar + dark mode). Option values must be
 * non-empty; use `placeholder` for the empty state.
 */
export function AppSelect({
    value, onChange, options, placeholder, id, className, disabled, 'aria-label': ariaLabel,
}: {
    value: string;
    onChange: (v: string) => void;
    options: AppSelectOption[];
    placeholder?: string;
    id?: string;
    className?: string;
    disabled?: boolean;
    'aria-label'?: string;
}) {
    return (
        <Select value={value || undefined} onValueChange={onChange} disabled={disabled}>
            <SelectTrigger id={id} aria-label={ariaLabel} className={cn('h-11 w-full', className)}>
                <SelectValue placeholder={placeholder ?? 'Select…'} />
            </SelectTrigger>
            <SelectContent>
                {options.map((o) => <SelectItem key={o.value} value={o.value} disabled={o.disabled}>{o.label}</SelectItem>)}
            </SelectContent>
        </Select>
    );
}
