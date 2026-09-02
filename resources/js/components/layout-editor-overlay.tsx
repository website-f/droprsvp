import { Minus, Plus, X } from 'lucide-react';
import { useState  } from 'react';
import type {ReactNode} from 'react';
import { SeatLayoutEditor } from '@/components/seat-layout-editor';
import type { LayoutSectionRow } from '@/components/seat-layout-editor';
import { TableLayoutEditor } from '@/components/table-layout-editor';
import type { PropRow, TableRow } from '@/components/table-layout-editor';
import { Button } from '@/components/ui/button';
import { boxSize } from '@/lib/seat-layout';
import { tableGeom } from '@/lib/table-geometry';

interface OverlayProps {
    open: boolean;
    onClose: () => void;
    mode: 'reserved' | 'tables';
    sections: LayoutSectionRow[];
    onSections: (v: LayoutSectionRow[]) => void;
    tables: TableRow[];
    onTables: (v: TableRow[]) => void;
    propsList: PropRow[];
    onProps: (v: PropRow[]) => void;
    toolbar?: ReactNode;
}

/** Full-screen floorplan editor with zoom, hosting the reserved OR table editor. */
export function LayoutEditorOverlay({ open, onClose, mode, sections, onSections, tables, onTables, propsList, onProps, toolbar }: OverlayProps) {
    const [zoom, setZoom] = useState(1);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[70] flex flex-col bg-background">
            <div className="flex items-center gap-3 border-b border-border px-4 py-2.5">
                <h2 className="text-sm font-semibold">{mode === 'tables' ? 'Table floorplan editor' : 'Reserved seating editor'}</h2>
                <div className="ml-auto flex items-center gap-0.5 rounded-lg border border-border p-0.5">
                    <button type="button" onClick={() => setZoom((z) => Math.max(0.4, +(z - 0.1).toFixed(2)))} className="flex size-7 items-center justify-center rounded-md hover:bg-accent" aria-label="Zoom out"><Minus className="size-4" /></button>
                    <button type="button" onClick={() => setZoom(1)} className="min-w-12 text-xs font-medium tabular-nums">{Math.round(zoom * 100)}%</button>
                    <button type="button" onClick={() => setZoom((z) => Math.min(2, +(z + 0.1).toFixed(2)))} className="flex size-7 items-center justify-center rounded-md hover:bg-accent" aria-label="Zoom in"><Plus className="size-4" /></button>
                </div>
                <Button size="sm" onClick={onClose}>Done</Button>
                <button type="button" onClick={onClose} aria-label="Close editor" className="flex size-8 items-center justify-center rounded-lg hover:bg-accent"><X className="size-5" /></button>
            </div>
            <div className="flex-1 overflow-auto p-4">
                <div className="mx-auto max-w-5xl">
                    {toolbar && <div className="mb-3 flex flex-wrap items-center gap-2">{toolbar}</div>}
                    {mode === 'tables'
                        ? <TableLayoutEditor tables={tables} onTables={onTables} props={propsList} onProps={onProps} zoom={zoom} />
                        : <SeatLayoutEditor value={sections} onChange={onSections} zoom={zoom} />}
                </div>
            </div>
        </div>
    );
}

interface Cell { x: number; y: number; w: number; h: number; round: boolean; color: string; label: string }

/** A small, static, scaled thumbnail of the current layout, shown in the ticket section. */
export function LayoutPreview({ mode, tables, propsList, sections }: { mode: 'reserved' | 'tables'; tables: TableRow[]; propsList: PropRow[]; sections: LayoutSectionRow[] }) {
    const cells: Cell[] = mode === 'tables'
        ? [
            ...propsList.map((p) => ({ x: p.pos_x, y: p.pos_y, w: p.width, h: p.height, round: false, color: p.color, label: p.label })),
            ...tables.map((t) => {
 const g = tableGeom(t.shape, t.capacity);

 return { x: t.pos_x, y: t.pos_y, w: g.size, h: g.size, round: t.shape === 'round', color: '#6c63ff', label: t.name }; 
}),
        ]
        : sections.map((s) => {
 const b = boxSize(s);

 return { x: s.x, y: s.y, w: b.w, h: b.h, round: false, color: s.color || '#6c63ff', label: s.name }; 
});

    if (cells.length === 0) {
        return <div className="flex h-32 items-center justify-center rounded-xl border border-dashed border-border text-sm text-muted-foreground">No layout yet — open the editor to design it.</div>;
    }

    const bw = Math.max(...cells.map((c) => c.x + c.w)) + 20;
    const bh = Math.max(...cells.map((c) => c.y + c.h)) + 20;
    const scale = Math.min(200 / bh, 560 / bw, 1);

    return (
        <div className="overflow-hidden rounded-xl border border-border bg-muted/20 p-2">
            <div className="relative mx-auto" style={{ width: bw * scale, height: bh * scale }}>
                {cells.map((c, i) => (
                    <div key={i} className={`absolute flex items-center justify-center overflow-hidden px-0.5 text-[8px] font-medium leading-none text-white ${c.round ? 'rounded-full' : 'rounded'}`} style={{ left: c.x * scale, top: c.y * scale, width: c.w * scale, height: c.h * scale, backgroundColor: c.color, opacity: 0.85 }}>{c.label}</div>
                ))}
            </div>
        </div>
    );
}
