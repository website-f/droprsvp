import { Minus, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';

export interface SeatMapSeat { id: number; label: string; row: string; number: number; taken: boolean }
export interface SeatMapSection {
    id: number; ticket_type_id: number | null; name: string; color: string; kind: 'seated' | 'ga';
    price: number; currency: string; rows: number | null; cols: number | null;
    remaining: number | null; on_sale: boolean; seats: SeatMapSeat[];
}

const rm = (n: number) => `RM ${n.toFixed(2)}`;

function groupByRow(seats: SeatMapSeat[]): [string, SeatMapSeat[]][] {
    const map = new Map<string, SeatMapSeat[]>();
    seats.forEach((s) => {
 const r = map.get(s.row) ?? []; r.push(s); map.set(s.row, r); 
});

    return [...map.entries()];
}

/**
 * Buyer-facing seat map. `selected` holds chosen seat ids; `gaQty` holds
 * quantity per general-admission section id.
 */
export function SeatMap({ sections, selected, onToggleSeat, gaQty, onGaChange }: {
    sections: SeatMapSection[];
    selected: Set<number>;
    onToggleSeat: (seat: SeatMapSeat, section: SeatMapSection) => void;
    gaQty: Record<number, number>;
    onGaChange: (sectionId: number, qty: number) => void;
}) {
    const hasSeated = sections.some((s) => s.kind === 'seated');

    return (
        <div className="grid gap-5">
            {hasSeated && (
                <div className="mx-auto w-3/4 rounded bg-foreground/85 py-1.5 text-center text-[11px] font-semibold uppercase tracking-[0.2em] text-background">Stage</div>
            )}

            {sections.map((section) => (
                <div key={section.id} className="grid gap-2">
                    <div className="flex items-center justify-between gap-2 text-sm">
                        <span className="flex items-center gap-2 font-medium">
                            <span className="size-3 rounded-full" style={{ backgroundColor: section.color }} /> {section.name}
                        </span>
                        <span className="text-muted-foreground">{section.price > 0 ? rm(section.price) : 'Free'}</span>
                    </div>

                    {!section.on_sale ? (
                        <p className="rounded-lg border border-dashed border-border p-3 text-center text-xs text-muted-foreground">Not on sale.</p>
                    ) : section.kind === 'ga' ? (
                        <div className="flex items-center justify-between rounded-xl border border-border p-3">
                            <span className="text-xs text-muted-foreground">{section.remaining === null ? 'Standing' : `${section.remaining} left`}</span>
                            <div className="flex items-center gap-2">
                                <Button type="button" variant="outline" size="icon" className="size-8" disabled={(gaQty[section.id] || 0) <= 0} onClick={() => onGaChange(section.id, (gaQty[section.id] || 0) - 1)} aria-label="Decrease"><Minus className="size-3.5" /></Button>
                                <span className="w-6 text-center text-sm tabular-nums">{gaQty[section.id] || 0}</span>
                                <Button type="button" variant="outline" size="icon" className="size-8" disabled={section.remaining !== null && (gaQty[section.id] || 0) >= section.remaining} onClick={() => onGaChange(section.id, (gaQty[section.id] || 0) + 1)} aria-label="Increase"><Plus className="size-3.5" /></Button>
                            </div>
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border border-border p-3">
                            <div className="mx-auto grid w-max gap-1.5">
                                {groupByRow(section.seats).map(([row, seats]) => (
                                    <div key={row} className="flex items-center gap-1.5">
                                        <span className="w-5 shrink-0 text-right text-[10px] font-medium text-muted-foreground">{row}</span>
                                        {seats.map((seat) => {
                                            const isSelected = selected.has(seat.id);

                                            return (
                                                <button
                                                    key={seat.id}
                                                    type="button"
                                                    disabled={seat.taken}
                                                    onClick={() => onToggleSeat(seat, section)}
                                                    title={`${section.name} · ${seat.label}`}
                                                    aria-label={`Seat ${seat.label}`}
                                                    aria-pressed={isSelected}
                                                    className={`flex size-6 items-center justify-center rounded-[4px] border text-[8px] font-medium transition-colors ${seat.taken ? 'cursor-not-allowed border-transparent bg-muted text-muted-foreground/40' : isSelected ? 'border-transparent text-white' : 'border-border hover:border-foreground/50'}`}
                                                    style={isSelected ? { backgroundColor: section.color } : seat.taken ? undefined : { color: section.color }}
                                                >
                                                    {seat.number}
                                                </button>
                                            );
                                        })}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            ))}

            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
                <span className="flex items-center gap-1.5"><span className="size-3 rounded-[3px] border border-border" /> Available</span>
                <span className="flex items-center gap-1.5"><span className="size-3 rounded-[3px] bg-foreground" /> Selected</span>
                <span className="flex items-center gap-1.5"><span className="size-3 rounded-[3px] bg-muted" /> Taken</span>
            </div>
        </div>
    );
}
