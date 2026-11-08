import { Check, Shuffle } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface TableOption { id: number; name: string; shape: 'round' | 'rect' | string; capacity: number; left: number }
export interface TableChoiceLine { item_id: number; name: string; quantity: number; chosen: number | null; tables: TableOption[] }
export interface TableChoice { lines: TableChoiceLine[]; limit: number | null; auto_seat: boolean }

/** A table drawn small: its shape, with a dot per seat — filled when taken. */
function TableGlyph({ table, mine }: { table: TableOption; mine: number }) {
    const seats = Math.min(table.capacity, 16);
    const taken = table.capacity - table.left;
    const round = table.shape !== 'rect';

    return (
        <svg viewBox="0 0 64 64" className="size-11 shrink-0" aria-hidden>
            {round ? <circle cx="32" cy="32" r="15" className="fill-muted stroke-border" strokeWidth="1.5" />
                : <rect x="16" y="20" width="32" height="24" rx="4" className="fill-muted stroke-border" strokeWidth="1.5" />}
            {Array.from({ length: seats }, (_, i) => {
                const a = (i / seats) * Math.PI * 2 - Math.PI / 2;
                const filled = i < taken;
                const yours = !filled && i < taken + mine;

                return (
                    <circle key={i} cx={32 + Math.cos(a) * 25} cy={32 + Math.sin(a) * 25} r="4"
                        className={cn(filled ? 'fill-muted-foreground/50' : yours ? 'fill-foreground' : 'fill-card stroke-muted-foreground/60')}
                        strokeWidth="1.2" />
                );
            })}
        </svg>
    );
}

/** One choice: a picture, the full name (wrapped, never cut off), a detail line and a radio mark. */
function Option({ active, disabled = false, onClick, icon, title, detail }: {
    active: boolean; disabled?: boolean; onClick: () => void; icon: React.ReactNode; title: string; detail: string;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={active}
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'flex min-h-16 w-full items-center gap-3 rounded-xl border p-3 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-55',
                active ? 'border-foreground bg-foreground/[0.04] ring-1 ring-foreground' : 'border-border bg-card hover:border-foreground/40',
            )}
        >
            {icon}
            <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium leading-snug [overflow-wrap:anywhere]">{title}</span>
                <span className="mt-0.5 block text-xs leading-snug text-muted-foreground">{detail}</span>
            </span>
            <span
                aria-hidden
                className={cn('flex size-5 shrink-0 items-center justify-center rounded-full border',
                    active ? 'border-foreground bg-foreground text-background' : 'border-muted-foreground/50')}
            >
                {active && <Check className="size-3" strokeWidth={3} />}
            </span>
        </button>
    );
}

/**
 * "Choose your table" at checkout: every table with the seats it has left,
 * drawn so a buyer can see at a glance where there is room for their group.
 * A table without room for the whole group cannot be picked; "No preference"
 * leaves it to the organizer.
 */
export function TablePicker({ choice, value, onChange, errors }: {
    choice: TableChoice;
    /** order item id => table id ('' = no preference) */
    value: Record<string, string>;
    onChange: (itemId: number, tableId: string) => void;
    errors: Record<string, string | undefined>;
}) {
    return (
        <div className="grid gap-5">
            {choice.lines.map((line) => {
                const picked = value[String(line.item_id)] ?? '';
                const roomy = line.tables.filter((t) => t.left >= line.quantity);

                return (
                    <div key={line.item_id} className="grid gap-2">
                        {choice.lines.length > 1 && (
                            <div className="text-xs font-medium text-muted-foreground">{line.quantity} × {line.name}</div>
                        )}
                        {roomy.length === 0 && (
                            <p className="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                                No table has {line.quantity} seats left together right now — the organizer will seat your group.
                            </p>
                        )}
                        {/* Cards size to the space the picker actually has (not the
                            screen), so names are never squeezed: one per row on a
                            phone or in a narrow column, two or three where it fits. */}
                        <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(min(100%,13.5rem),1fr))]" role="radiogroup" aria-label={`Table for ${line.name}`}>
                            <Option
                                active={picked === ''}
                                onClick={() => onChange(line.item_id, '')}
                                icon={<span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-muted"><Shuffle className="size-5 text-muted-foreground" /></span>}
                                title="No preference"
                                detail={choice.auto_seat ? 'Seat us at any table' : 'The organizer seats you'}
                            />
                            {line.tables.map((t) => {
                                const fits = t.left >= line.quantity;
                                const active = picked === String(t.id);

                                return (
                                    <Option
                                        key={t.id}
                                        active={active}
                                        disabled={!fits}
                                        onClick={() => onChange(line.item_id, String(t.id))}
                                        icon={<TableGlyph table={t} mine={active ? line.quantity : 0} />}
                                        title={t.name}
                                        detail={t.left === 0 ? 'Full' : !fits ? `Only ${t.left} left — not enough for ${line.quantity}` : `${t.left} of ${t.capacity} seats left${active ? ` · ${line.quantity} for you` : ''}`}
                                    />
                                );
                            })}
                        </div>
                        {errors[`tables.${line.item_id}`] && <p className="text-xs text-destructive">{errors[`tables.${line.item_id}`]}</p>}
                    </div>
                );
            })}
            {errors.tables && <p className="text-xs text-destructive">{errors.tables}</p>}
        </div>
    );
}
