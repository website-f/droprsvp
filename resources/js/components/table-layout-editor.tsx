import { Circle, Plus, RectangleHorizontal, RotateCw, Trash2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { newProp, PROP_KINDS,  SEAT, tableGeom } from '@/lib/table-geometry';
import type {PropRow} from '@/lib/table-geometry';

export type { PropRow } from '@/lib/table-geometry';

export interface TableRow {
    id?: number;
    name: string;
    shape: 'round' | 'rect';
    capacity: number;
    pos_x: number;
    pos_y: number;
    rotation: number;
}

/** A fresh table, staggered so new ones don't land on top of each other. */
export function newTable(index: number): TableRow {
    return {
        name: `Table ${index + 1}`,
        shape: 'round',
        capacity: 8,
        rotation: 0,
        pos_x: 24 + (index % 4) * 150,
        pos_y: 24 + Math.floor(index / 4) * 160,
    };
}

const field = 'h-9 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const PROP_ORDER = ['stage', 'entrance', 'dancefloor', 'catering', 'reception', 'gift', 'booth', 'photo', 'vip', 'restroom', 'walkway', 'parking', 'custom'];

type Sel = { t: 'table' | 'prop'; i: number } | null;

/**
 * Banquet floorplan editor — drag round/rect tables and custom props (stage,
 * entrance, dance floor…), rotate and resize them, and set seats per table.
 * `zoom` scales the canvas; drag/resize deltas are divided by it so editing stays
 * 1:1 at any zoom.
 */
export function TableLayoutEditor({
    tables, onTables, props, onProps, zoom = 1,
}: {
    tables: TableRow[];
    onTables: (v: TableRow[]) => void;
    props: PropRow[];
    onProps: (v: PropRow[]) => void;
    zoom?: number;
}) {
    const [sel, setSel] = useState<Sel>(null);
    const drag = useRef<{ kind: 'table' | 'prop'; i: number; sx: number; sy: number; ox: number; oy: number } | null>(null);
    const rez = useRef<{ i: number; sx: number; sy: number; ow: number; oh: number } | null>(null);

    const patchTable = (i: number, c: Partial<TableRow>) => onTables(tables.map((t, idx) => (idx === i ? { ...t, ...c } : t)));
    const patchProp = (i: number, c: Partial<PropRow>) => onProps(props.map((p, idx) => (idx === i ? { ...p, ...c } : p)));
    const addTable = () => {
 onTables([...tables, newTable(tables.length)]); setSel({ t: 'table', i: tables.length }); 
};
    const addProp = (kind: string) => {
 if (!kind) {
 return; 
}

 onProps([...props, newProp(kind, props.length)]); setSel({ t: 'prop', i: props.length }); 
};
    const removeTable = (i: number) => {
 onTables(tables.filter((_, idx) => idx !== i)); setSel(null); 
};
    const removeProp = (i: number) => {
 onProps(props.filter((_, idx) => idx !== i)); setSel(null); 
};

    const startDrag = (e: React.PointerEvent, kind: 'table' | 'prop', i: number) => {
        e.preventDefault();
        setSel({ t: kind, i });
        const src = kind === 'table' ? tables[i] : props[i];
        drag.current = { kind, i, sx: e.clientX, sy: e.clientY, ox: src.pos_x, oy: src.pos_y };

        try {
 (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId); 
} catch { /* optional */ }
    };
    const onDragMove = (e: React.PointerEvent) => {
        const d = drag.current;

        if (!d) {
 return; 
}

        const nx = Math.max(0, Math.round(d.ox + (e.clientX - d.sx) / zoom));
        const ny = Math.max(0, Math.round(d.oy + (e.clientY - d.sy) / zoom));

        if (d.kind === 'table') {
            patchTable(d.i, { pos_x: nx, pos_y: ny });
        } else {
            patchProp(d.i, { pos_x: nx, pos_y: ny });
        }
    };
    const endDrag = () => {
 drag.current = null; 
};

    const startResize = (e: React.PointerEvent, i: number) => {
        e.preventDefault();
        e.stopPropagation();
        setSel({ t: 'prop', i });
        rez.current = { i, sx: e.clientX, sy: e.clientY, ow: props[i].width, oh: props[i].height };

        try {
 (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId); 
} catch { /* optional */ }
    };
    const onResizeMove = (e: React.PointerEvent) => {
        const d = rez.current;

        if (!d) {
 return; 
}

        patchProp(d.i, {
            width: Math.max(30, Math.round(d.ow + (e.clientX - d.sx) / zoom)),
            height: Math.max(30, Math.round(d.oh + (e.clientY - d.sy) / zoom)),
        });
    };
    const endResize = () => {
 rez.current = null; 
};

    // Canvas size (ignoring rotation — the canvas is padded + scrollable).
    const bw = Math.max(720, ...tables.map((t) => t.pos_x + tableGeom(t.shape, t.capacity).size + 40), ...props.map((p) => p.pos_x + p.width + 40));
    const bh = Math.max(460, ...tables.map((t) => t.pos_y + tableGeom(t.shape, t.capacity).size + 40), ...props.map((p) => p.pos_y + p.height + 40));

    const selTable = sel?.t === 'table' ? tables[sel.i] : null;
    const selProp = sel?.t === 'prop' ? props[sel.i] : null;

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <Button type="button" variant="outline" size="sm" onClick={addTable}><Plus className="size-3.5" /> Add table</Button>
                <div className="w-44"><AppSelect value="" onChange={addProp} options={[{ value: '', label: 'Add prop…' }, ...PROP_ORDER.map((k) => ({ value: k, label: PROP_KINDS[k][0] }))]} /></div>
                <span className="text-xs text-muted-foreground">Drag to arrange · click to edit · rotate &amp; resize in the panel.</span>
            </div>

            {/* Canvas */}
            <div
                className="relative h-full max-h-[70vh] min-h-[380px] w-full overflow-auto rounded-xl border border-border bg-[radial-gradient(circle,theme(colors.border)_1px,transparent_1px)] [background-size:20px_20px]"
                onPointerDown={(e) => {
 if (e.target === e.currentTarget) {
setSel(null);
} 
}}
            >
                <div style={{ width: bw * zoom, height: bh * zoom }}>
                    <div className="relative" style={{ width: bw, height: bh, transform: `scale(${zoom})`, transformOrigin: '0 0' }}>
                        {tables.length === 0 && props.length === 0 && (
                            <div className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">Add a table or prop to start your floorplan.</div>
                        )}

                        {/* Props (behind tables) */}
                        {props.map((p, i) => {
                            const active = sel?.t === 'prop' && sel.i === i;

                            return (
                                <div
                                    key={`p-${i}`}
                                    onPointerDown={(e) => startDrag(e, 'prop', i)}
                                    onPointerMove={onDragMove}
                                    onPointerUp={endDrag}
                                    style={{ left: p.pos_x, top: p.pos_y, width: p.width, height: p.height, transform: `rotate(${p.rotation}deg)` }}
                                    className={`group absolute flex cursor-move touch-none select-none items-center justify-center rounded-lg border-2 text-center text-[11px] font-semibold uppercase tracking-wide text-white shadow-sm ${active ? 'ring-2 ring-foreground' : ''}`}
                                >
                                    <span className="absolute inset-0 rounded-md opacity-90" style={{ backgroundColor: p.color }} />
                                    <span className="relative px-1">{p.label}</span>
                                    <button type="button" aria-label="Delete prop" onPointerDown={(e) => e.stopPropagation()} onClick={(e) => {
 e.stopPropagation(); removeProp(i); 
}} className={`absolute -right-2 -top-2 z-10 flex size-5 items-center justify-center rounded-full bg-destructive text-white shadow ${active ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'}`}><X className="size-3" /></button>
                                    <span role="button" aria-label="Resize" onPointerDown={(e) => startResize(e, i)} onPointerMove={onResizeMove} onPointerUp={endResize} className={`absolute -bottom-1.5 -right-1.5 z-10 size-3.5 cursor-nwse-resize rounded-sm border-2 border-foreground bg-background ${active ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'}`} />
                                </div>
                            );
                        })}

                        {/* Tables */}
                        {tables.map((t, i) => {
                            const geo = tableGeom(t.shape, t.capacity);
                            const active = sel?.t === 'table' && sel.i === i;

                            return (
                                <div
                                    key={`t-${i}`}
                                    onPointerDown={(e) => startDrag(e, 'table', i)}
                                    onPointerMove={onDragMove}
                                    onPointerUp={endDrag}
                                    style={{ left: t.pos_x, top: t.pos_y, width: geo.size, height: geo.size, transform: `rotate(${t.rotation}deg)` }}
                                    className={`group absolute cursor-move touch-none select-none rounded-2xl ${active ? 'ring-2 ring-foreground' : ''}`}
                                >
                                    {geo.seats.map((s, si) => <span key={si} style={{ left: s.x, top: s.y, width: SEAT, height: SEAT }} className="absolute rounded-full bg-foreground/25" />)}
                                    <div style={{ left: geo.body.left, top: geo.body.top, width: geo.body.w, height: geo.body.h }} className={`absolute flex items-center justify-center border border-foreground/30 bg-foreground/10 text-center text-[10px] font-semibold leading-tight text-foreground ${geo.body.round ? 'rounded-full' : 'rounded-md'}`}>
                                        <span className="px-1">{t.name}<br /><span className="font-normal text-muted-foreground">{t.capacity} seats</span></span>
                                    </div>
                                    <button type="button" aria-label="Delete table" onPointerDown={(e) => e.stopPropagation()} onClick={(e) => {
 e.stopPropagation(); removeTable(i); 
}} className={`absolute right-1 top-1 z-10 flex size-5 items-center justify-center rounded-full bg-destructive text-white shadow ${active ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'}`}><X className="size-3" /></button>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>

            {/* Table properties */}
            {selTable && sel && (
                <div className="grid gap-3 rounded-xl border border-border bg-card p-3 sm:grid-cols-[1fr_auto_90px_auto]">
                    <div className="grid gap-1"><Label className="text-xs">Table name</Label><input className={field} value={selTable.name} onChange={(e) => patchTable(sel.i, { name: e.target.value })} /></div>
                    <div className="grid gap-1">
                        <Label className="text-xs">Shape</Label>
                        <div className="inline-flex h-9 rounded-lg border border-border p-0.5">
                            <button type="button" onClick={() => patchTable(sel.i, { shape: 'round' })} className={`flex items-center gap-1 rounded-md px-2.5 text-xs font-medium ${selTable.shape === 'round' ? 'bg-foreground text-background' : 'text-muted-foreground'}`}><Circle className="size-3.5" /> Round</button>
                            <button type="button" onClick={() => patchTable(sel.i, { shape: 'rect' })} className={`flex items-center gap-1 rounded-md px-2.5 text-xs font-medium ${selTable.shape === 'rect' ? 'bg-foreground text-background' : 'text-muted-foreground'}`}><RectangleHorizontal className="size-3.5" /> Rect</button>
                        </div>
                    </div>
                    <div className="grid gap-1"><Label className="text-xs">Seats</Label><input type="number" min={1} max={1000} className={field} value={selTable.capacity} onChange={(e) => patchTable(sel.i, { capacity: Math.max(1, Number(e.target.value) || 1) })} /></div>
                    <div className="flex items-end"><Button type="button" variant="ghost" size="icon" className="size-9" aria-label="Remove table" onClick={() => removeTable(sel.i)}><Trash2 className="size-4" /></Button></div>
                    <div className="sm:col-span-4"><RotateControl value={selTable.rotation} onChange={(v) => patchTable(sel.i, { rotation: v })} /></div>
                </div>
            )}

            {/* Prop properties */}
            {selProp && sel && (
                <div className="grid gap-3 rounded-xl border border-border bg-card p-3">
                    <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                        <div className="grid gap-1"><Label className="text-xs">Label</Label><input className={field} value={selProp.label} onChange={(e) => patchProp(sel.i, { label: e.target.value })} /></div>
                        <div className="grid gap-1"><Label className="text-xs">Type</Label><AppSelect value={selProp.kind} onChange={(v) => patchProp(sel.i, { kind: v })} options={PROP_ORDER.map((k) => ({ value: k, label: PROP_KINDS[k][0] }))} /></div>
                        <div className="flex items-end"><Button type="button" variant="ghost" size="icon" className="size-9" aria-label="Remove prop" onClick={() => removeProp(sel.i)}><Trash2 className="size-4" /></Button></div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-1"><Label className="text-xs">Width</Label><input type="number" min={30} max={2000} className={field} value={selProp.width} onChange={(e) => patchProp(sel.i, { width: Math.max(30, Number(e.target.value) || 30) })} /></div>
                        <div className="grid gap-1"><Label className="text-xs">Height</Label><input type="number" min={30} max={2000} className={field} value={selProp.height} onChange={(e) => patchProp(sel.i, { height: Math.max(30, Number(e.target.value) || 30) })} /></div>
                        <div className="grid gap-1"><Label className="text-xs">Colour</Label><input type="color" value={selProp.color} onChange={(e) => patchProp(sel.i, { color: e.target.value })} className="h-9 w-full cursor-pointer rounded-lg border border-input bg-card p-1" /></div>
                    </div>
                    <RotateControl value={selProp.rotation} onChange={(v) => patchProp(sel.i, { rotation: v })} />
                </div>
            )}
        </div>
    );
}

function RotateControl({ value, onChange }: { value: number; onChange: (v: number) => void }) {
    return (
        <div className="grid gap-1">
            <Label className="flex items-center gap-1.5 text-xs"><RotateCw className="size-3.5" /> Rotation — <span className="font-normal text-muted-foreground">{value}°</span></Label>
            <input type="range" min={0} max={359} step={1} value={value} onChange={(e) => onChange(Number(e.target.value))} className="w-full accent-foreground" />
        </div>
    );
}
