import { Minus, Plus, ZoomIn, ZoomOut } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { boxSize, contentBounds, HEADER, SEAT } from '@/lib/seat-layout';

export interface SeatMapSeat { id: number; label: string; row: string; number: number; taken: boolean }
export interface SeatMapSection {
    id: number; ticket_type_id: number | null; name: string; color: string; kind: 'seated' | 'ga' | 'stage';
    price: number; currency: string; rows: number | null; cols: number | null;
    x: number; y: number; width: number | null; height: number | null;
    remaining: number | null; on_sale: boolean; seats: SeatMapSeat[];
}

const rm = (n: number) => `RM ${n.toFixed(2)}`;

/**
 * Buyer-facing seat map — renders the organizer's real venue layout. Seated
 * blocks show tappable seats; standing blocks add via a stepper. Pinch-free
 * zoom + scroll keeps it usable on phones.
 */
export function SeatMap({ sections, selected, onToggleSeat, gaQty, onGaChange }: {
    sections: SeatMapSection[];
    selected: Set<number>;
    onToggleSeat: (seat: SeatMapSeat, section: SeatMapSection) => void;
    gaQty: Record<number, number>;
    onGaChange: (sectionId: number, qty: number) => void;
}) {
    const bounds = contentBounds(sections.map((s) => ({ ...s, x: s.x, y: s.y })));
    const wrapRef = useRef<HTMLDivElement>(null);
    const [zoom, setZoom] = useState(1);

    // Fit to the container width on first render (so big halls aren't cut off on mobile).
    useEffect(() => {
        const w = wrapRef.current?.clientWidth ?? bounds.w;
        setZoom(Math.max(0.45, Math.min(1, (w - 4) / bounds.w)));
    }, [bounds.w]);

    const gaSections = sections.filter((s) => s.kind === 'ga');

    return (
        <div className="grid gap-3">
            <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">Tap a seat to select</span>
                <div className="flex items-center gap-1">
                    <Button type="button" variant="outline" size="icon" className="size-8" onClick={() => setZoom((z) => Math.max(0.45, +(z - 0.15).toFixed(2)))} aria-label="Zoom out"><ZoomOut className="size-3.5" /></Button>
                    <Button type="button" variant="outline" size="icon" className="size-8" onClick={() => setZoom((z) => Math.min(2, +(z + 0.15).toFixed(2)))} aria-label="Zoom in"><ZoomIn className="size-3.5" /></Button>
                </div>
            </div>

            <div ref={wrapRef} className="overflow-auto rounded-xl border border-border bg-muted/20 p-2">
                <div style={{ width: bounds.w * zoom, height: bounds.h * zoom }}>
                    <div className="relative origin-top-left" style={{ width: bounds.w, height: bounds.h, transform: `scale(${zoom})` }}>
                        {sections.map((section) => {
                            const b = boxSize(section);

                            if (section.kind === 'stage') {
                                return (
                                    <div key={section.id} className="absolute flex items-center justify-center rounded-lg text-xs font-semibold uppercase tracking-[0.2em] text-white" style={{ left: section.x, top: section.y, width: b.w, height: b.h, backgroundColor: section.color || '#111827' }}>
                                        {section.name || 'STAGE'}
                                    </div>
                                );
                            }

                            return (
                                <div key={section.id} className="absolute rounded-lg border" style={{ left: section.x, top: section.y, width: b.w, height: b.h, backgroundColor: `${section.color}12`, borderColor: `${section.color}55` }}>
                                    <div className="flex h-6 items-center gap-1 px-2 text-[11px] font-semibold" style={{ color: section.color }}>
                                        <span className="size-2 rounded-full" style={{ backgroundColor: section.color }} /> {section.name} · {section.price > 0 ? rm(section.price) : 'Free'}
                                    </div>

                                    {section.kind === 'ga' ? (
                                        <div className="flex flex-col items-center justify-center gap-1" style={{ height: b.h - HEADER }}>
                                            <span className="text-[11px] text-muted-foreground">{section.remaining === null ? 'Standing' : `${section.remaining} left`}</span>
                                            <span className="rounded-full px-2 py-0.5 text-xs font-bold text-white" style={{ backgroundColor: section.color }}>{gaQty[section.id] || 0} selected</span>
                                        </div>
                                    ) : (
                                        <div className="px-2 pb-2">
                                            <div className="grid gap-[3px]" style={{ gridTemplateColumns: `repeat(${Math.max(1, section.cols || 1)}, ${SEAT - 3}px)` }}>
                                                {section.seats.map((seat) => {
                                                    const isSel = selected.has(seat.id);

                                                    return (
                                                        <button
                                                            key={seat.id}
                                                            type="button"
                                                            disabled={seat.taken || !section.on_sale}
                                                            onClick={() => onToggleSeat(seat, section)}
                                                            title={`${section.name} · ${seat.label}`}
                                                            aria-label={`Seat ${seat.label}`}
                                                            aria-pressed={isSel}
                                                            className={`flex items-center justify-center rounded-[3px] border text-[7px] font-semibold transition-colors ${seat.taken ? 'cursor-not-allowed border-transparent bg-muted-foreground/20 text-transparent' : isSel ? 'border-transparent text-white' : 'border-current hover:opacity-80'}`}
                                                            style={{ width: SEAT - 3, height: SEAT - 3, ...(isSel ? { backgroundColor: section.color } : seat.taken ? {} : { color: section.color }) }}
                                                        >
                                                            {seat.number}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>

            {/* Standing-area steppers (their seats aren't assigned) */}
            {gaSections.length > 0 && (
                <div className="grid gap-2">
                    {gaSections.map((s) => (
                        <div key={s.id} className="flex items-center justify-between rounded-xl border border-border p-3 text-sm">
                            <span className="flex items-center gap-2 font-medium"><span className="size-3 rounded-full" style={{ backgroundColor: s.color }} /> {s.name} · {s.price > 0 ? rm(s.price) : 'Free'}</span>
                            <div className="flex items-center gap-2">
                                <Button type="button" variant="outline" size="icon" className="size-8" disabled={(gaQty[s.id] || 0) <= 0} onClick={() => onGaChange(s.id, (gaQty[s.id] || 0) - 1)} aria-label="Decrease"><Minus className="size-3.5" /></Button>
                                <span className="w-6 text-center tabular-nums">{gaQty[s.id] || 0}</span>
                                <Button type="button" variant="outline" size="icon" className="size-8" disabled={!s.on_sale || (s.remaining !== null && (gaQty[s.id] || 0) >= s.remaining)} onClick={() => onGaChange(s.id, (gaQty[s.id] || 0) + 1)} aria-label="Increase"><Plus className="size-3.5" /></Button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
                <span className="flex items-center gap-1.5"><span className="size-3 rounded-[3px] border border-foreground/40" /> Available</span>
                <span className="flex items-center gap-1.5"><span className="size-3 rounded-[3px] bg-foreground" /> Selected</span>
                <span className="flex items-center gap-1.5"><span className="size-3 rounded-[3px] bg-muted-foreground/20" /> Taken</span>
            </div>
        </div>
    );
}
