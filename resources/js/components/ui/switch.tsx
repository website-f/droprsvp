import { cn } from '@/lib/utils';

interface SwitchProps {
    checked: boolean;
    onCheckedChange: (value: boolean) => void;
    disabled?: boolean;
    id?: string;
    name?: string;
    'aria-label'?: string;
    className?: string;
}

/**
 * A responsive, theme-aware on/off toggle used everywhere in place of native
 * checkboxes. Dependency-free (a real <button role="switch">) so it never
 * inherits browser checkbox styling quirks.
 */
export function Switch({ checked, onCheckedChange, disabled, id, name, className, ...rest }: SwitchProps) {
    return (
        <button
            type="button"
            role="switch"
            id={id}
            name={name}
            aria-checked={checked}
            aria-label={rest['aria-label']}
            disabled={disabled}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-foreground' : 'bg-input',
                className,
            )}
        >
            <span className={cn('inline-block size-5 rounded-full bg-background shadow transition-transform', checked ? 'translate-x-[1.375rem]' : 'translate-x-0.5')} />
        </button>
    );
}

interface SwitchFieldProps extends SwitchProps {
    label: React.ReactNode;
    description?: React.ReactNode;
    className?: string;
}

/** A labelled row: title (+ optional description) on the left, the toggle on the right. */
export function SwitchField({ label, description, className, ...toggle }: SwitchFieldProps) {
    return (
        <div className={cn('flex items-center justify-between gap-4', toggle.disabled && 'opacity-60', className)}>
            <div className="min-w-0">
                <div className="text-sm font-medium">{label}</div>
                {description && <div className="mt-0.5 text-xs text-muted-foreground">{description}</div>}
            </div>
            <Switch {...toggle} />
        </div>
    );
}
