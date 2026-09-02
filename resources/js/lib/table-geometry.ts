/* Pure geometry for drawing a banquet table (round or rectangular) with its seats,
 * shared by the layout editor. Functions of (shape, capacity) only — no DOM. */

export const SEAT = 14; // seat-dot diameter in px

export interface TableGeo {
    size: number; // the square the whole table (body + seats) occupies
    body: { left: number; top: number; w: number; h: number; round: boolean };
    seats: { x: number; y: number }[];
}

export function roundTable(capacity: number): TableGeo {
    const cap = Math.max(1, capacity);
    const bodyD = Math.max(46, Math.round(((SEAT + 8) * cap) / Math.PI));
    const ringR = bodyD / 2 + SEAT * 0.9 + 3;
    const size = Math.round(2 * ringR + SEAT + 6);
    const c = size / 2;
    const seats = Array.from({ length: cap }, (_, i) => {
        const a = (i / cap) * Math.PI * 2 - Math.PI / 2;

        return { x: c + ringR * Math.cos(a) - SEAT / 2, y: c + ringR * Math.sin(a) - SEAT / 2 };
    });

    return { size, body: { left: c - bodyD / 2, top: c - bodyD / 2, w: bodyD, h: bodyD, round: true }, seats };
}

export function rectTable(capacity: number): TableGeo {
    const cap = Math.max(1, capacity);
    const per = Math.ceil(cap / 2);
    const bodyW = Math.max(64, per * (SEAT + 8));
    const bodyH = 42;
    const size = Math.max(bodyW, bodyH) + SEAT * 2 + 14;
    const left = Math.round((size - bodyW) / 2);
    const top = Math.round((size - bodyH) / 2);
    const seats: { x: number; y: number }[] = [];
    const place = (n: number, y: number) => {
        for (let i = 0; i < n; i++) {
            const slot = bodyW / n;
            seats.push({ x: left + slot * (i + 0.5) - SEAT / 2, y });
        }
    };
    place(per, top - SEAT - 3);
    place(cap - per, top + bodyH + 3);

    return { size, body: { left, top, w: bodyW, h: bodyH, round: false }, seats };
}

export const tableGeom = (shape: 'round' | 'rect', capacity: number): TableGeo =>
    shape === 'round' ? roundTable(capacity) : rectTable(capacity);
