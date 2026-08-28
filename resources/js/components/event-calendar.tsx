import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight, MapPin } from 'lucide-react';

export interface CalendarEvent {
    id: number | string;
    title: string;
    date: string;          // ISO date/datetime; the YYYY-MM-DD prefix is used
    time?: string | null;
    venue?: string | null;
    url?: string;
    tone?: 'default' | 'muted';
}

const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** Responsive month calendar. Desktop: 6×7 grid with event chips. Mobile: same
 *  grid (compact) + a tap-to-view agenda for the selected day below. */
export function EventCalendar({ events }: { events: CalendarEvent[] }) {
    const [cursor, setCursor] = useState(() => { const n = new Date(); return new Date(n.getFullYear(), n.getMonth(), 1); });
    const [selected, setSelected] = useState<string | null>(null);
    const todayKey = ymd(new Date());

    const byDay = useMemo(() => {
        const map: Record<string, CalendarEvent[]> = {};
        for (const e of events) { const k = e.date.slice(0, 10); (map[k] ||= []).push(e); }
        return map;
    }, [events]);

    const days = useMemo(() => {
        const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
        const start = new Date(first);
        start.setDate(1 - first.getDay()); // rewind to the Sunday on/just before the 1st
        return Array.from({ length: 42 }, (_, i) => { const d = new Date(start); d.setDate(start.getDate() + i); return d; });
    }, [cursor]);

    const move = (delta: number) => { setCursor((c) => new Date(c.getFullYear(), c.getMonth() + delta, 1)); setSelected(null); };
    const goToday = () => { const n = new Date(); setCursor(new Date(n.getFullYear(), n.getMonth(), 1)); setSelected(ymd(n)); };

    const selectedEvents = selected ? (byDay[selected] ?? []) : [];

    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-sm sm:p-5">
            {/* header */}
            <div className="mb-4 flex items-center justify-between gap-2">
                <h2 className="text-base font-semibold sm:text-lg">{MONTHS[cursor.getMonth()]} {cursor.getFullYear()}</h2>
                <div className="flex items-center gap-1">
                    <button type="button" aria-label="Previous month" onClick={() => move(-1)} className="flex size-8 items-center justify-center rounded-lg border border-border hover:bg-accent"><ChevronLeft className="size-4" /></button>
                    <button type="button" onClick={goToday} className="rounded-lg border border-border px-3 py-1.5 text-xs font-medium hover:bg-accent">Today</button>
                    <button type="button" aria-label="Next month" onClick={() => move(1)} className="flex size-8 items-center justify-center rounded-lg border border-border hover:bg-accent"><ChevronRight className="size-4" /></button>
                </div>
            </div>

            {/* weekday row */}
            <div className="grid grid-cols-7 border-b border-border pb-2 text-center text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                {DOW.map((d) => <div key={d}><span className="sm:hidden">{d[0]}</span><span className="hidden sm:inline">{d}</span></div>)}
            </div>

            {/* grid */}
            <div className="grid grid-cols-7">
                {days.map((d, i) => {
                    const key = ymd(d);
                    const inMonth = d.getMonth() === cursor.getMonth();
                    const dayEvents = byDay[key] ?? [];
                    const isToday = key === todayKey;
                    const isSelected = key === selected;
                    return (
                        <button
                            key={i}
                            type="button"
                            onClick={() => setSelected(key)}
                            className={`min-h-[64px] border-b border-r border-border p-1 text-left align-top transition-colors sm:min-h-[104px] sm:p-1.5 ${i % 7 === 0 ? 'border-l' : ''} ${inMonth ? '' : 'bg-muted/30 text-muted-foreground'} ${isSelected ? 'ring-2 ring-inset ring-foreground' : 'hover:bg-accent/50'}`}
                        >
                            <span className={`inline-flex size-6 items-center justify-center rounded-full text-xs ${isToday ? 'bg-foreground font-semibold text-background' : ''}`}>{d.getDate()}</span>
                            {/* chips (desktop) */}
                            <div className="mt-1 hidden flex-col gap-1 sm:flex">
                                {dayEvents.slice(0, 2).map((e) => (
                                    <span key={e.id} className={`truncate rounded px-1.5 py-0.5 text-[11px] font-medium ${e.tone === 'muted' ? 'bg-secondary text-muted-foreground' : 'bg-primary/10 text-primary'}`}>{e.title}</span>
                                ))}
                                {dayEvents.length > 2 && <span className="px-1 text-[10px] text-muted-foreground">+{dayEvents.length - 2} more</span>}
                            </div>
                            {/* dots (mobile) */}
                            {dayEvents.length > 0 && (
                                <div className="mt-1 flex gap-0.5 sm:hidden">
                                    {dayEvents.slice(0, 3).map((e) => <span key={e.id} className="size-1.5 rounded-full bg-primary" />)}
                                </div>
                            )}
                        </button>
                    );
                })}
            </div>

            {/* selected-day agenda */}
            {selected && (
                <div className="mt-4">
                    <h3 className="mb-2 text-sm font-semibold">
                        {new Date(selected + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })}
                    </h3>
                    {selectedEvents.length === 0 ? (
                        <p className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">No events this day.</p>
                    ) : (
                        <ul className="grid gap-2">
                            {selectedEvents.map((e) => {
                                const body = (
                                    <div className="flex items-center gap-3 rounded-lg border border-border bg-background p-3">
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><CalendarDays className="size-4" /></span>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{e.title}</p>
                                            <p className="flex items-center gap-1.5 truncate text-xs text-muted-foreground">{e.time}{e.venue && <><MapPin className="size-3" />{e.venue}</>}</p>
                                        </div>
                                    </div>
                                );
                                return <li key={e.id}>{e.url ? <Link href={e.url}>{body}</Link> : body}</li>;
                            })}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
