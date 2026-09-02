import { Circle, Plus, RectangleHorizontal, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SEAT, tableGeom } from '@/lib/table-geometry';

export interface TableRow {
    id?: number;
    name: string;
    shape: 'round' | 'rect';
    capacity: number;
    pos_x: number;
    pos_y: number;
}

/** A fresh table, staggered so new ones don't land on top of each other. */
export function newTable(index: number): TableRow {
    return {
        name: `Table ${index + 1}`,
        shape: 'round',
        capacity: 8,
        pos_x: 24 + (index % 4) * 150,
        pos_y: 24 + Math.floor(index / 4) * 160,
    };
}

const field = 'h-9 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/** Draggable floorplan of banquet tables — set name, shape, seats and position. */
export function TableLayoutEditor({ value, onChange }: { value: TableRow[]; onChange: (v: TableRow[]) => void }) {
    const canvasRef = useRef<HTMLDivElement>(null);
    const [selected, setSelected] = useState<number | null>(null);
    const [dragIdx, setDragIdx] = useState<number | null>(null);
    const offset = useRef({ dx: 0, dy: 0 });

    const patch = (i: number, changes: Partial<TableRow>) => onChange(value.map((t, idx) => (idx === i ? { ...t, ...changes } : t)));
    const add = () => {
 onChange([...value, newTable(value.length)]); setSelected(value.length); 
};
    const remove = (i: number) => {
 onChange(value.filter((_, idx) => idx !== i)); setSelected(null); 
};

    // Drag: re-subscribes as `value` changes so it never reads a stale position.
    useEffect(() => {
        if (dragIdx === null) {
            return;
        }

        const onMove = (e: PointerEvent) => {
            const rect = canvasRef.current?.getBoundingClientRect();

            if (!rect) {
                return;
            }

            const x = Math.max(0, Math.round(e.clientX - rect.left + (canvasRef.current?.scrollLeft ?? 0) - offset.current.dx));
            const y = Math.max(0, Math.round(e.clientY - rect.top + (canvasRef.current?.scrollTop ?? 0) - offset.current.dy));
            onChange(value.map((t, i) => (i === dragIdx ? { ...t, pos_x: x, pos_y: y } : t)));
        };
        const onUp = () => setDragIdx(null);
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);

        return () => {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
        };
    }, [dragIdx, value, onChange]);

    const startDrag = (e: React.PointerEvent, i: number) => {
        const rect = canvasRef.current?.getBoundingClientRect();

        if (!rect) {
            return;
        }

        offset.current = {
            dx: e.clientX - rect.left + (canvasRef.current?.scrollLeft ?? 0) - value[i].pos_x,
            dy: e.clientY - rect.top + (canvasRef.current?.scrollTop ?? 0) - value[i].pos_y,
        };
        setSelected(i);
        setDragIdx(i);
    };

    const sel = selected !== null ? value[selected] : null;

    return (
        <div className="grid gap-3">
            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">Add tables, drag to arrange your floorplan, and set how many seats each holds.</p>
                <Button type="button" variant="outline" size="sm" onClick={add}><Plus className="size-3.5" /> Add table</Button>
            </div>

            {/* Canvas */}
            <div
                ref={canvasRef}
                className="relative h-[380px] w-full overflow-auto rounded-xl border border-border bg-[radial-gradient(circle,theme(colors.border)_1px,transparent_1px)] [background-size:20px_20px]"
                onPointerDown={(e) => {
 if (e.target === e.currentTarget) {
setSelected(null);
} 
}}
            >
                {value.length === 0 && (
                    <div className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">No tables yet — add your first table.</div>
                )}
                {value.map((t, i) => {
                    const geo = tableGeom(t.shape, t.capacity);
                    const isSel = selected === i;

                    return (
                        <div
                            key={i}
                            role="button"
                            tabIndex={0}
                            onPointerDown={(e) => startDrag(e, i)}
                            style={{ left: t.pos_x, top: t.pos_y, width: geo.size, height: geo.size }}
                            className={`absolute cursor-grab touch-none select-none rounded-2xl transition-shadow active:cursor-grabbing ${isSel ? 'ring-2 ring-foreground' : ''}`}
                        >
                            {/* Seats */}
                            {geo.seats.map((s, si) => (
                                <span key={si} style={{ left: s.x, top: s.y, width: SEAT, height: SEAT }} className="absolute rounded-full bg-foreground/25" />
                            ))}
                            {/* Table body */}
                            <div
                                style={{ left: geo.body.left, top: geo.body.top, width: geo.body.w, height: geo.body.h }}
                                className={`absolute flex items-center justify-center bg-foreground/10 text-center text-[10px] font-semibold leading-tight text-foreground ${geo.body.round ? 'rounded-full' : 'rounded-md'} border border-foreground/30`}
                            >
                                <span className="px-1">{t.name}<br /><span className="font-normal text-muted-foreground">{t.capacity} seats</span></span>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Properties of the selected table */}
            {sel && selected !== null && (
                <div className="grid gap-3 rounded-xl border border-border bg-card p-3 sm:grid-cols-[1fr_auto_100px_auto]">
                    <div className="grid gap-1">
                        <Label className="text-xs">Table name</Label>
                        <input className={field} value={sel.name} onChange={(e) => patch(selected, { name: e.target.value })} />
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Shape</Label>
                        <div className="inline-flex h-9 rounded-lg border border-border p-0.5">
                            <button type="button" onClick={() => patch(selected, { shape: 'round' })} className={`flex items-center gap-1 rounded-md px-2.5 text-xs font-medium ${sel.shape === 'round' ? 'bg-foreground text-background' : 'text-muted-foreground'}`}><Circle className="size-3.5" /> Round</button>
                            <button type="button" onClick={() => patch(selected, { shape: 'rect' })} className={`flex items-center gap-1 rounded-md px-2.5 text-xs font-medium ${sel.shape === 'rect' ? 'bg-foreground text-background' : 'text-muted-foreground'}`}><RectangleHorizontal className="size-3.5" /> Rect</button>
                        </div>
                    </div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Seats</Label>
                        <input type="number" min={1} max={1000} className={field} value={sel.capacity} onChange={(e) => patch(selected, { capacity: Math.max(1, Number(e.target.value) || 1) })} />
                    </div>
                    <div className="flex items-end">
                        <Button type="button" variant="ghost" size="icon" className="size-9" aria-label="Remove table" onClick={() => remove(selected)}><Trash2 className="size-4" /></Button>
                    </div>
                </div>
            )}
        </div>
    );
}
