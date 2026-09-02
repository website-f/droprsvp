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

/** Floorplan prop kinds → [default label, default width, default height, default colour]. */
export const PROP_KINDS: Record<string, [string, number, number, string]> = {
    stage: ['Stage', 260, 120, '#111827'],
    entrance: ['Entrance', 150, 60, '#6c63ff'],
    reception: ['Reception', 180, 70, '#2ec4b6'],
    catering: ['Buffet', 300, 80, '#f5a524'],
    gift: ['Gift table', 170, 70, '#a855f7'],
    booth: ['Booth', 160, 100, '#3b82f6'],
    photo: ['Photo booth', 160, 110, '#ff6584'],
    dancefloor: ['Dance floor', 220, 180, '#10b981'],
    vip: ['VIP', 200, 90, '#ef4444'],
    restroom: ['Restroom', 130, 80, '#64748b'],
    walkway: ['Walkway', 340, 60, '#94a3b8'],
    parking: ['Parking', 240, 120, '#64748b'],
    custom: ['Prop', 200, 90, '#6c63ff'],
};

export interface PropRow {
    id?: number;
    kind: string;
    label: string;
    color: string;
    pos_x: number;
    pos_y: number;
    width: number;
    height: number;
    rotation: number;
}

export function newProp(kind: string, index: number): PropRow {
    const [label, width, height, color] = PROP_KINDS[kind] ?? PROP_KINDS.custom;

    return {
        kind, label, color, width, height, rotation: 0,
        pos_x: 40 + (index % 3) * 60,
        pos_y: 40 + (index % 3) * 40,
    };
}
