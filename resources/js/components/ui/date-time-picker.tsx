import { useEffect, useMemo, useRef, useState } from 'react';
import { CalendarDays, ChevronLeft, ChevronRight, X } from 'lucide-react';

const DOW = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

const parse = (v: string) => { const [d, t] = (v || '').split('T'); return { date: d || '', time: (t || '').slice(0, 5) }; };
const compose = (date: string, time: string) => (date ? `${date}T${time || '00:00'}` : '');

const HOURS = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));
// Every minute, not 5-minute steps — a scheduled post goes out at the exact
// time the author set. The columns scroll; the boxes above them take typing.
const MINS = Array.from({ length: 60 }, (_, i) => String(i).padStart(2, '0'));
const pad2 = (n: number) => String(n).padStart(2, '0');
const clamp = (n: number, max: number) => Math.min(max, Math.max(0, n));

/**
 * Custom, responsive date + time picker — replaces native datetime-local.
 * Value format is "YYYY-MM-DDTHH:mm" (same as datetime-local) so callers and
 * the backend are unchanged.
 */
export function DateTimePicker({ value, onChange, id, placeholder = 'Pick date & time' }: {
    value: string;
    onChange: (v: string) => void;
    id?: string;
    placeholder?: string;
}) {
    const { date, time } = parse(value);
    const [hh, mm] = time ? time.split(':') : ['', ''];
    const [open, setOpen] = useState(false);
    const [cursor, setCursor] = useState(() => {
        const base = date ? new Date(`${date}T00:00:00`) : new Date();
        return new Date(base.getFullYear(), base.getMonth(), 1);
    });
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onDoc = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false); };
        const onEsc = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onEsc);
        return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onEsc); };
    }, [open]);

    const days = useMemo(() => {
        const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
        const start = new Date(first); start.setDate(1 - first.getDay());
        return Array.from({ length: 42 }, (_, i) => { const d = new Date(start); d.setDate(start.getDate() + i); return d; });
    }, [cursor]);

    const label = value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '';
    const pickDay = (d: Date) => onChange(compose(ymd(d), time || '19:00'));
    const setHH = (h: string) => onChange(compose(date || ymd(new Date()), `${h}:${mm || '00'}`));
    const setMM = (m: string) => onChange(compose(date || ymd(new Date()), `${hh || '19'}:${m}`));

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                id={id}
                onClick={() => setOpen((o) => !o)}
                className="flex h-11 w-full items-center gap-2 rounded-lg border border-input bg-card px-3 text-left text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
            >
                <CalendarDays className="size-4 shrink-0 text-muted-foreground" />
                <span className={value ? 'truncate' : 'truncate text-muted-foreground'}>{value ? label : placeholder}</span>
                {value && <X className="ml-auto size-3.5 shrink-0 text-muted-foreground hover:text-foreground" onClick={(e) => { e.stopPropagation(); onChange(''); }} />}
            </button>

            {open && (
                <div className="absolute left-0 z-50 mt-2 w-[300px] rounded-xl border border-border bg-popover p-3 text-popover-foreground shadow-lg">
                    {/* month header */}
                    <div className="mb-2 flex items-center justify-between">
                        <span className="text-sm font-semibold">{MONTHS[cursor.getMonth()]} {cursor.getFullYear()}</span>
                        <div className="flex items-center gap-1">
                            <button type="button" aria-label="Previous month" onClick={() => setCursor((c) => new Date(c.getFullYear(), c.getMonth() - 1, 1))} className="flex size-7 items-center justify-center rounded-md hover:bg-accent"><ChevronLeft className="size-4" /></button>
                            <button type="button" aria-label="Next month" onClick={() => setCursor((c) => new Date(c.getFullYear(), c.getMonth() + 1, 1))} className="flex size-7 items-center justify-center rounded-md hover:bg-accent"><ChevronRight className="size-4" /></button>
                        </div>
                    </div>
                    {/* weekday row */}
                    <div className="grid grid-cols-7 text-center text-[11px] font-medium text-muted-foreground">
                        {DOW.map((d, i) => <div key={i}>{d}</div>)}
                    </div>
                    {/* days */}
                    <div className="mt-1 grid grid-cols-7 gap-0.5">
                        {days.map((d, i) => {
                            const inMonth = d.getMonth() === cursor.getMonth();
                            const isSel = ymd(d) === date;
                            return (
                                <button
                                    key={i}
                                    type="button"
                                    onClick={() => pickDay(d)}
                                    className={`flex h-8 items-center justify-center rounded-md text-sm ${isSel ? 'bg-foreground font-semibold text-background' : inMonth ? 'hover:bg-accent' : 'text-muted-foreground/50 hover:bg-accent'}`}
                                >{d.getDate()}</button>
                            );
                        })}
                    </div>
                    {/* time — type an exact HH:MM, or scroll-pick below */}
                    <div className="mt-3 border-t border-border pt-3">
                        <div className="mb-1.5 flex items-center justify-between">
                            <span className="text-xs font-medium text-muted-foreground">Time</span>
                            <div className="flex items-center gap-1">
                                <TimeBox label="Hour" max={23} value={hh} onCommit={setHH} />
                                <span className="text-sm font-semibold text-muted-foreground">:</span>
                                <TimeBox label="Minute" max={59} value={mm} onCommit={setMM} />
                            </div>
                        </div>
                        <div className="flex gap-2">
                            <TimeColumn label="Hour" items={HOURS} value={hh} onSelect={setHH} />
                            <TimeColumn label="Min" items={MINS} value={mm} onSelect={setMM} />
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * A two-digit hour/minute box you can type into. Keystrokes are held locally so
 * a half-typed "1" isn't immediately normalised to "01"; the value is committed
 * (clamped and padded) on blur, Enter, or an arrow key.
 */
function TimeBox({ label, max, value, onCommit }: { label: string; max: number; value: string; onCommit: (v: string) => void }) {
    const [draft, setDraft] = useState<string | null>(null);
    // Drop a half-typed draft the moment the value changes from elsewhere (the
    // scroll columns, or clearing the field) so the box can't show a stale digit.
    const [lastSeen, setLastSeen] = useState(value);

    if (value !== lastSeen) {
        setLastSeen(value);
        setDraft(null);
    }

    const shown = draft ?? value;

    const commit = (raw: string) => {
        setDraft(null);

        if (raw.trim() === '') {
            return;
        }

        onCommit(pad2(clamp(parseInt(raw, 10) || 0, max)));
    };

    const nudge = (by: number) => {
        const current = parseInt(shown, 10);
        onCommit(pad2(clamp((Number.isNaN(current) ? 0 : current) + by, max)));
        setDraft(null);
    };

    return (
        <input
            type="text"
            inputMode="numeric"
            aria-label={label}
            value={shown}
            placeholder="--"
            onChange={(e) => setDraft(e.target.value.replace(/\D/g, '').slice(0, 2))}
            onBlur={(e) => commit(e.target.value)}
            onKeyDown={(e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    commit(e.currentTarget.value);
                }

                if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    nudge(e.key === 'ArrowUp' ? 1 : -1);
                }
            }}
            className="h-7 w-9 rounded-md border border-input bg-card text-center text-sm tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
        />
    );
}

function TimeColumn({ label, items, value, onSelect }: { label: string; items: string[]; value: string; onSelect: (v: string) => void }) {
    return (
        <div className="flex-1">
            <div className="max-h-36 overflow-y-auto rounded-lg border border-border">
                {items.map((it) => (
                    <button
                        key={it}
                        type="button"
                        ref={value === it ? (el) => el?.scrollIntoView({ block: 'nearest' }) : undefined}
                        onClick={() => onSelect(it)}
                        className={`block w-full px-3 py-1.5 text-center text-sm ${value === it ? 'bg-foreground text-background' : 'hover:bg-accent'}`}
                    >{it}</button>
                ))}
            </div>
            <div className="mt-1 text-center text-[10px] uppercase tracking-wide text-muted-foreground">{label}</div>
        </div>
    );
}
