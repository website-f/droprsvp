import { Maximize, Minus, Plus, RotateCw } from 'lucide-react';
import { useRef, useState  } from 'react';
import type {RefObject} from 'react';

export interface PanZoom {
    zoom: number;
    pan: { x: number; y: number };
    containerRef: RefObject<HTMLDivElement | null>;
    onWheel: (e: React.WheelEvent) => void;
    startPan: (e: React.PointerEvent) => void;
    onPanMove: (e: React.PointerEvent) => void;
    endPan: () => void;
    zoomBy: (factor: number) => void;
    reset: () => void;
}

/**
 * Infinite-canvas pan + zoom (n8n / Figma style): scroll-wheel zooms toward the
 * cursor, dragging empty space pans. Item drags must divide their deltas by `zoom`.
 */
export function usePanZoom(): PanZoom {
    const [zoom, setZoom] = useState(1);
    const [pan, setPan] = useState({ x: 0, y: 0 });
    const containerRef = useRef<HTMLDivElement>(null);
    const dragging = useRef<{ sx: number; sy: number; px: number; py: number } | null>(null);

    const clamp = (z: number) => Math.min(3, Math.max(0.25, +z.toFixed(3)));

    const zoomAt = (clientX: number, clientY: number, factor: number) => {
        const rect = containerRef.current?.getBoundingClientRect();

        if (!rect) {
            return;
        }

        const cx = clientX - rect.left;
        const cy = clientY - rect.top;
        const nz = clamp(zoom * factor);
        // Keep the point under the cursor fixed while scaling.
        setPan({ x: cx - ((cx - pan.x) / zoom) * nz, y: cy - ((cy - pan.y) / zoom) * nz });
        setZoom(nz);
    };

    const onWheel = (e: React.WheelEvent) => {
        e.preventDefault();
        zoomAt(e.clientX, e.clientY, e.deltaY < 0 ? 1.12 : 1 / 1.12);
    };
    const zoomBy = (factor: number) => {
        const rect = containerRef.current?.getBoundingClientRect();

        if (rect) {
            zoomAt(rect.left + rect.width / 2, rect.top + rect.height / 2, factor);
        }
    };
    const startPan = (e: React.PointerEvent) => {
        dragging.current = { sx: e.clientX, sy: e.clientY, px: pan.x, py: pan.y };

        try {
 (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId); 
} catch { /* optional */ }
    };
    const onPanMove = (e: React.PointerEvent) => {
        const d = dragging.current;

        if (!d) {
            return;
        }

        setPan({ x: d.px + (e.clientX - d.sx), y: d.py + (e.clientY - d.sy) });
    };
    const endPan = () => {
 dragging.current = null; 
};
    const reset = () => {
 setZoom(1); setPan({ x: 0, y: 0 }); 
};

    return { zoom, pan, containerRef, onWheel, startPan, onPanMove, endPan, zoomBy, reset };
}

/** Floating zoom buttons pinned to a pan/zoom canvas. */
export function ZoomControls({ zoom, zoomBy, reset }: Pick<PanZoom, 'zoom' | 'zoomBy' | 'reset'>) {
    return (
        <div className="absolute bottom-3 right-3 z-20 flex items-center gap-0.5 rounded-lg border border-border bg-card/90 p-0.5 shadow-sm backdrop-blur" onPointerDown={(e) => e.stopPropagation()} onWheel={(e) => e.stopPropagation()}>
            <button type="button" onClick={() => zoomBy(1 / 1.2)} className="flex size-7 items-center justify-center rounded-md hover:bg-accent" aria-label="Zoom out"><Minus className="size-4" /></button>
            <button type="button" onClick={reset} className="min-w-12 text-center text-xs font-medium tabular-nums hover:text-foreground" aria-label="Reset view">{Math.round(zoom * 100)}%</button>
            <button type="button" onClick={() => zoomBy(1.2)} className="flex size-7 items-center justify-center rounded-md hover:bg-accent" aria-label="Zoom in"><Plus className="size-4" /></button>
            <button type="button" onClick={reset} className="flex size-7 items-center justify-center rounded-md hover:bg-accent" aria-label="Fit to view"><Maximize className="size-3.5" /></button>
        </div>
    );
}

/**
 * Drag-to-rotate handle on the top edge of the selected item (Photoshop/AutoCAD
 * style). The parent item element must carry `data-floor-item`.
 */
export function RotateHandle({ onChange }: { onChange: (deg: number) => void }) {
    const start = (e: React.PointerEvent) => {
        e.preventDefault();
        e.stopPropagation();
        const box = (e.currentTarget as HTMLElement).closest('[data-floor-item]')?.getBoundingClientRect();

        if (!box) {
            return;
        }

        const cx = box.left + box.width / 2;
        const cy = box.top + box.height / 2;
        const move = (ev: PointerEvent) => {
            const ang = (Math.atan2(ev.clientY - cy, ev.clientX - cx) * 180) / Math.PI + 90;
            onChange(((Math.round(ang) % 360) + 360) % 360);
        };
        const up = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    };

    return (
        <>
            <span className="pointer-events-none absolute left-1/2 top-0 h-5 w-px -translate-x-1/2 -translate-y-full bg-foreground/70" />
            <span role="button" aria-label="Rotate" onPointerDown={start} className="absolute left-1/2 top-0 z-20 flex size-4 -translate-x-1/2 -translate-y-[calc(100%+8px)] cursor-grab items-center justify-center rounded-full border-2 border-foreground bg-background"><RotateCw className="size-2.5" /></span>
        </>
    );
}
