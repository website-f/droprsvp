// Shared geometry for the reserved-seating canvas (host editor + buyer picker).
// Everything is in "logical units" (roughly px at 100% zoom).

export const SEAT = 22;      // one seat cell
export const HEADER = 24;    // section title strip

export interface LayoutSection {
    kind: 'seated' | 'ga' | 'stage';
    rows?: number | string | null;
    cols?: number | string | null;
    width?: number | string | null;
    height?: number | string | null;
    curve?: number | string | null;
}

const num = (v: number | string | null | undefined, fallback = 0) => {
    const n = typeof v === 'string' ? parseInt(v, 10) : v;

    return n && !Number.isNaN(n) ? n : fallback;
};

/** How far the ends of a curved row dip below its centre (0 when straight). */
export function arcDepth(s: LayoutSection): number {
    const cols = Math.max(1, num(s.cols, 1));
    const curve = Math.max(0, Math.min(100, num(s.curve, 0)));

    if (curve === 0 || cols < 2) {
        return 0;
    }

    return Math.round((curve / 100) * Math.min(SEAT * 6, cols * SEAT * 0.45));
}

/** Local (x,y) of a seat at row/col inside its section's seat area. */
export function seatXY(s: LayoutSection, rowIdx: number, colIdx: number): { x: number; y: number } {
    const cols = Math.max(1, num(s.cols, 1));
    const depth = arcDepth(s);
    let arc = 0;

    if (depth > 0 && cols > 1) {
        const t = (colIdx - (cols - 1) / 2) / ((cols - 1) / 2); // -1 … 1

        arc = Math.round(depth * t * t);
    }

    return { x: colIdx * SEAT, y: rowIdx * SEAT + arc };
}

/** Size of a seated section's seat area (excludes the header). */
export function seatsInnerSize(s: LayoutSection): { w: number; h: number } {
    const cols = Math.max(1, num(s.cols, 1));
    const rows = Math.max(1, num(s.rows, 1));

    return { w: cols * SEAT, h: rows * SEAT + arcDepth(s) };
}

/** Footprint of a section on the canvas. */
export function boxSize(s: LayoutSection): { w: number; h: number } {
    if (s.kind === 'seated') {
        const cols = Math.max(1, Math.min(100, num(s.cols, 1)));
        const rows = Math.max(1, Math.min(100, num(s.rows, 1)));

        return { w: cols * SEAT + 16, h: HEADER + rows * SEAT + arcDepth(s) + 10 };
    }

    if (s.kind === 'stage') {
        return { w: num(s.width, 280), h: num(s.height, 48) };
    }

    return { w: num(s.width, 180), h: num(s.height, 110) }; // ga
}

/** Overall canvas size needed to fit every section (plus a margin). */
export function contentBounds(sections: Array<LayoutSection & { x: number; y: number }>): { w: number; h: number } {
    let w = 420;
    let h = 300;

    for (const s of sections) {
        const b = boxSize(s);
        w = Math.max(w, (s.x || 0) + b.w + 24);
        h = Math.max(h, (s.y || 0) + b.h + 24);
    }

    return { w, h };
}
