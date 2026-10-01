import { ChevronDown, Filter, MessageSquareText, Sparkles, UserRound, X } from 'lucide-react';
import { useState } from 'react';

export interface FacetOption { value: string; label: string; count: number }
export interface Facet {
    key: string;
    label: string;
    /** question = the organizer's own booking question; demographic = checkout profile; flag = yes/no. */
    kind: 'question' | 'demographic' | 'flag';
    options: FacetOption[];
}

/**
 * Filter chips built from the event itself, each with a live count.
 *
 * The facets come from App\Support\AttendeeDirectory: one per choice-type
 * booking question the organizer wrote, plus whichever checkout demographics
 * buyers actually filled in. Nothing here is hard-coded to a particular event —
 * an event asking "Drink with your ticket" grows a drink filter, one that never
 * asked for a city does not grow an empty city filter.
 *
 * Counts are faceted (each group counted against the OTHER active filters), so
 * the bars double as the answer to "how many of each?" without filtering at all,
 * and narrow sensibly once something is picked.
 */
export function AttendeeFacets({
    facets,
    active,
    onChange,
    onClear,
}: {
    facets: Facet[];
    active: Record<string, string>;
    onChange: (key: string, value: string | null) => void;
    onClear: () => void;
}) {
    const activeCount = Object.keys(active).length;
    // Open by default when the event asked questions — that is the case this
    // panel exists for — and whenever a filter is already applied.
    const [open, setOpen] = useState(activeCount > 0 || facets.some((f) => f.kind === 'question'));

    if (facets.length === 0) {
        return null;
    }

    const questions = facets.filter((f) => f.kind === 'question');
    const people = facets.filter((f) => f.kind !== 'question');

    return (
        <section className="mb-4 rounded-xl border border-border bg-card">
            <div className="flex flex-wrap items-center gap-2 px-4 py-3">
                <button
                    type="button"
                    onClick={() => setOpen((o) => !o)}
                    aria-expanded={open}
                    className="flex min-w-0 flex-1 items-center gap-2 text-left text-sm font-semibold"
                >
                    <Filter className="size-4 shrink-0 text-muted-foreground" />
                    <span>Filter by answers &amp; profile</span>
                    {activeCount > 0 && (
                        <span className="rounded-full bg-foreground px-2 py-0.5 text-[11px] font-semibold text-background">{activeCount} active</span>
                    )}
                    <ChevronDown className={`ml-auto size-4 shrink-0 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`} />
                </button>
                {activeCount > 0 && (
                    <button type="button" onClick={onClear} className="text-xs font-medium text-muted-foreground underline-offset-2 hover:text-foreground hover:underline">
                        Clear all
                    </button>
                )}
            </div>

            {/* What is applied, readable at a glance even with the panel shut. */}
            {activeCount > 0 && (
                <div className="flex flex-wrap gap-1.5 border-t border-border px-4 py-2.5">
                    {Object.entries(active).map(([key, value]) => {
                        const facet = facets.find((f) => f.key === key);
                        const option = facet?.options.find((o) => o.value === value);

                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => onChange(key, null)}
                                className="inline-flex items-center gap-1 rounded-full bg-foreground px-2.5 py-1 text-xs font-medium text-background"
                            >
                                {/* A question already ends in "?" — "Played before?: Veteran" reads wrong. */}
                                <span className="opacity-70">{facet?.label ?? key}{/[?:]$/.test(facet?.label ?? '') ? '' : ':'}</span> {option?.label ?? value}
                                <X className="size-3" />
                            </button>
                        );
                    })}
                </div>
            )}

            {open && (
                <div className="grid gap-5 border-t border-border px-4 py-4">
                    {questions.length > 0 && (
                        <Group icon={<Sparkles className="size-3.5" />} title="Your booking questions">
                            {questions.map((f) => <FacetRow key={f.key} facet={f} active={active[f.key]} onChange={onChange} />)}
                        </Group>
                    )}
                    {people.length > 0 && (
                        <Group icon={<UserRound className="size-3.5" />} title="Who they are">
                            {people.map((f) => <FacetRow key={f.key} facet={f} active={active[f.key]} onChange={onChange} />)}
                        </Group>
                    )}
                </div>
            )}
        </section>
    );
}

function Group({ icon, title, children }: { icon: React.ReactNode; title: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-3">
            <h3 className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{icon} {title}</h3>
            <div className="grid gap-4">{children}</div>
        </div>
    );
}

function FacetRow({ facet, active, onChange }: { facet: Facet; active?: string; onChange: (key: string, value: string | null) => void }) {
    // The largest option sets the scale for the little bars, so the busiest
    // answer is always a full bar and the rest read relative to it.
    const max = Math.max(1, ...facet.options.map((o) => o.count));

    return (
        <div className="grid gap-1.5">
            <div className="flex items-center gap-1.5 text-sm font-medium">
                {facet.kind === 'flag' && <MessageSquareText className="size-3.5 text-muted-foreground" />}
                {facet.label}
            </div>
            <div className="flex flex-wrap gap-1.5">
                {facet.options.map((option) => {
                    const on = active === option.value;
                    const empty = option.count === 0 && !on;

                    return (
                        <button
                            key={option.value}
                            type="button"
                            aria-pressed={on}
                            disabled={empty}
                            onClick={() => onChange(facet.key, on ? null : option.value)}
                            className={`group relative overflow-hidden rounded-full border px-3 py-1.5 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40 ${
                                on ? 'border-foreground bg-foreground text-background' : 'border-border bg-background hover:border-foreground/40'
                            }`}
                        >
                            {/* Proportion bar behind the label. */}
                            {!on && (
                                <span
                                    aria-hidden
                                    className="absolute inset-y-0 left-0 bg-muted transition-[width]"
                                    style={{ width: `${(option.count / max) * 100}%` }}
                                />
                            )}
                            <span className="relative flex items-center gap-1.5">
                                {option.label}
                                <span className={`tabular-nums ${on ? 'opacity-80' : 'text-muted-foreground'}`}>{option.count}</span>
                            </span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
