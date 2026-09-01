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
}

const num = (v: number | string | null | undefined, fallback = 0) => {
    const n = typeof v === 'string' ? parseInt(v, 10) : v;

    return n && !Number.isNaN(n) ? n : fallback;
};

/** Footprint of a section on the canvas. */
export function boxSize(s: LayoutSection): { w: number; h: number } {
    if (s.kind === 'seated') {
        const cols = Math.max(1, Math.min(100, num(s.cols, 1)));
        const rows = Math.max(1, Math.min(100, num(s.rows, 1)));

        return { w: cols * SEAT + 16, h: HEADER + rows * SEAT + 10 };
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
