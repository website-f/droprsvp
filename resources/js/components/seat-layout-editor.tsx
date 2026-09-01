import { Armchair, LayoutPanelTop, Trash2, Users } from 'lucide-react';
import { useRef, useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { boxSize, contentBounds, HEADER, SEAT } from '@/lib/seat-layout';

export interface LayoutSectionRow {
    id?: number;
    name: string; color: string; kind: 'seated' | 'ga' | 'stage';
    price: string; rows: string; cols: string; capacity: string;
    x: number; y: number; width: number | null; height: number | null; row_label_start: string;
}

const COLORS = ['#6c63ff', '#2ec4b6', '#f5a524', '#ff6584', '#3b82f6', '#a855f7', '#ef4444', '#10b981'];
const field = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export function newSection(kind: LayoutSectionRow['kind'], x = 40, y = 40, i = 0): LayoutSectionRow {
    return {
        name: kind === 'stage' ? 'STAGE' : kind === 'ga' ? 'Standing' : 'Section',
        color: kind === 'stage' ? '#111827' : COLORS[i % COLORS.length],
        kind, price: '0', rows: '4', cols: '8', capacity: '100',
        x, y, width: kind === 'stage' ? 280 : kind === 'ga' ? 180 : null, height: kind === 'stage' ? 48 : kind === 'ga' ? 110 : null,
        row_label_start: 'A',
    };
}

/** Miniature seat grid drawn inside a seated section box. */
function MiniSeats({ rows, cols, color }: { rows: number; cols: number; color: string }) {
    return (
        <div className="grid gap-[2px]" style={{ gridTemplateColumns: `repeat(${cols}, ${SEAT - 4}px)` }}>
            {Array.from({ length: rows * cols }).map((_, i) => (
                <span key={i} className="rounded-[2px]" style={{ width: SEAT - 4, height: SEAT - 4, backgroundColor: color, opacity: 0.85 }} />
            ))}
        </div>
    );
}

export function SeatLayoutEditor({ value, onChange }: { value: LayoutSectionRow[]; onChange: (next: LayoutSectionRow[]) => void }) {
    const [selected, setSelected] = useState<number | null>(null);
    const drag = useRef<{ i: number; sx: number; sy: number; ox: number; oy: number } | null>(null);

    // Pointer-capture drag: events route to the captured element across re-renders,
    // so each handler closes over the current `value`/`onChange` (no window listeners).
    const startDrag = (e: React.PointerEvent, i: number) => {
        e.preventDefault();
        setSelected(i);
        drag.current = { i, sx: e.clientX, sy: e.clientY, ox: value[i].x, oy: value[i].y };
        try {
            (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
        } catch {
            /* capture is a nicety — dragging still works without it */
        }
    };
    const onDragMove = (e: React.PointerEvent) => {
        const d = drag.current;

        if (!d) {
            return;
        }

        const nx = Math.max(0, Math.round(d.ox + (e.clientX - d.sx)));
        const ny = Math.max(0, Math.round(d.oy + (e.clientY - d.sy)));
        onChange(value.map((s, idx) => (idx === d.i ? { ...s, x: nx, y: ny } : s)));
    };
    const endDrag = () => {
 drag.current = null; 
};

    const patch = (i: number, key: keyof LayoutSectionRow, val: string | number | null) =>
        onChange(value.map((s, idx) => (idx === i ? { ...s, [key]: val } : s)));
    const add = (kind: LayoutSectionRow['kind']) => {
        const bounds = contentBounds(value.map((s) => ({ ...s, x: s.x, y: s.y })));
        onChange([...value, newSection(kind, 40, kind === 'stage' ? 20 : Math.min(bounds.h - 40, 120), value.length)]);
        setSelected(value.length);
    };
    const remove = (i: number) => {
 onChange(value.filter((_, idx) => idx !== i)); setSelected(null); 
};

    const bounds = contentBounds(value.map((s) => ({ ...s, x: s.x, y: s.y })));
    const sel = selected !== null ? value[selected] : null;

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" onClick={() => add('seated')}><Armchair className="size-3.5" /> Seated block</Button>
                <Button type="button" variant="outline" size="sm" onClick={() => add('ga')}><Users className="size-3.5" /> Standing area</Button>
                <Button type="button" variant="outline" size="sm" onClick={() => add('stage')}><LayoutPanelTop className="size-3.5" /> Stage / label</Button>
                <span className="self-center text-xs text-muted-foreground">Drag blocks to match your venue. Click one to edit it.</span>
            </div>

            {/* Canvas */}
            <div className="max-h-[520px] overflow-auto rounded-xl border border-border bg-[repeating-linear-gradient(45deg,transparent,transparent_11px,rgba(0,0,0,0.02)_11px,rgba(0,0,0,0.02)_12px)]">
                <div className="relative" style={{ width: bounds.w, height: bounds.h }}>
                    {value.length === 0 && (
                        <div className="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">Add a stage and some blocks to build your layout.</div>
                    )}
                    {value.map((s, i) => {
                        const b = boxSize(s);
                        const active = selected === i;

                        return (
                            <div
                                key={i}
                                onPointerDown={(e) => startDrag(e, i)}
                                onPointerMove={onDragMove}
                                onPointerUp={endDrag}
                                className={`absolute cursor-move touch-none select-none rounded-lg border-2 shadow-sm ${active ? 'border-foreground ring-2 ring-foreground/20' : 'border-transparent'}`}
                                style={{ left: s.x, top: s.y, width: b.w, height: b.h, backgroundColor: s.kind === 'stage' ? s.color : `${s.color}14` }}
                            >
                                {s.kind === 'stage' ? (
                                    <div className="flex size-full items-center justify-center text-xs font-semibold uppercase tracking-[0.2em] text-white">{s.name || 'STAGE'}</div>
                                ) : (
                                    <>
                                        <div className="flex h-6 items-center gap-1 px-2 text-[11px] font-semibold" style={{ color: s.color }}>
                                            <span className="size-2 rounded-full" style={{ backgroundColor: s.color }} /> {s.name || 'Section'}
                                        </div>
                                        <div className="flex items-center justify-center px-1 pb-1" style={{ height: b.h - HEADER }}>
                                            {s.kind === 'seated'
                                                ? <MiniSeats rows={Math.max(1, +s.rows || 1)} cols={Math.max(1, +s.cols || 1)} color={s.color} />
                                                : <span className="text-xs font-medium" style={{ color: s.color }}>{s.capacity || 0} standing</span>}
                                        </div>
                                    </>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Property panel */}
            {sel && selected !== null && (
                <div className="grid gap-3 rounded-xl border border-border bg-muted/30 p-4">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-semibold capitalize">{sel.kind === 'ga' ? 'Standing area' : sel.kind} settings</h3>
                        <Button type="button" variant="ghost" size="icon" aria-label="Delete block" onClick={() => remove(selected)}><Trash2 className="size-4" /></Button>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label>{sel.kind === 'stage' ? 'Label' : 'Name'}</Label>
                            <input className={field} value={sel.name} onChange={(e) => patch(selected, 'name', e.target.value)} />
                        </div>
                        {sel.kind !== 'stage' && (
                            <div className="grid gap-1.5">
                                <Label>Price (RM)</Label>
                                <input type="number" min={0} step="0.01" className={field} value={sel.price} onChange={(e) => patch(selected, 'price', e.target.value)} />
                            </div>
                        )}
                    </div>

                    {sel.kind === 'seated' && (
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="grid gap-1.5"><Label>Rows</Label><input type="number" min={1} max={100} className={field} value={sel.rows} onChange={(e) => patch(selected, 'rows', e.target.value)} /></div>
                            <div className="grid gap-1.5"><Label>Seats / row</Label><input type="number" min={1} max={100} className={field} value={sel.cols} onChange={(e) => patch(selected, 'cols', e.target.value)} /></div>
                            <div className="grid gap-1.5"><Label>Row labels start at</Label><input maxLength={1} className={field} value={sel.row_label_start} onChange={(e) => patch(selected, 'row_label_start', e.target.value.toUpperCase().replace(/[^A-Z]/g, ''))} placeholder="A" /></div>
                        </div>
                    )}
                    {sel.kind === 'ga' && (
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="grid gap-1.5"><Label>Capacity</Label><input type="number" min={1} className={field} value={sel.capacity} onChange={(e) => patch(selected, 'capacity', e.target.value)} /></div>
                            <div className="grid gap-1.5"><Label>Width</Label><input type="number" min={80} max={600} className={field} value={sel.width ?? 180} onChange={(e) => patch(selected, 'width', +e.target.value || 180)} /></div>
                            <div className="grid gap-1.5"><Label>Height</Label><input type="number" min={60} max={600} className={field} value={sel.height ?? 110} onChange={(e) => patch(selected, 'height', +e.target.value || 110)} /></div>
                        </div>
                    )}
                    {sel.kind === 'stage' && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid gap-1.5"><Label>Width</Label><input type="number" min={80} max={800} className={field} value={sel.width ?? 280} onChange={(e) => patch(selected, 'width', +e.target.value || 280)} /></div>
                            <div className="grid gap-1.5"><Label>Height</Label><input type="number" min={30} max={300} className={field} value={sel.height ?? 48} onChange={(e) => patch(selected, 'height', +e.target.value || 48)} /></div>
                        </div>
                    )}

                    {sel.kind !== 'stage' && (
                        <div className="grid gap-1.5">
                            <Label>Colour</Label>
                            <div className="flex flex-wrap gap-2">
                                {COLORS.map((c) => (
                                    <button key={c} type="button" aria-label={`Colour ${c}`} onClick={() => patch(selected, 'color', c)} className={`size-7 rounded-full border-2 ${sel.color === c ? 'border-foreground' : 'border-transparent'}`} style={{ backgroundColor: c }} />
                                ))}
                                <input type="color" value={sel.color} onChange={(e) => patch(selected, 'color', e.target.value)} className="size-7 cursor-pointer rounded-full border border-input bg-card p-0.5" aria-label="Custom colour" />
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Type selector for the selected block */}
            {sel && selected !== null && (
                <div className="grid max-w-xs gap-1.5">
                    <Label>Block type</Label>
                    <AppSelect value={sel.kind} onChange={(v) => patch(selected, 'kind', v)} options={[{ value: 'seated', label: 'Seated (rows)' }, { value: 'ga', label: 'Standing (GA)' }, { value: 'stage', label: 'Stage / label' }]} />
                </div>
            )}
        </div>
    );
}
