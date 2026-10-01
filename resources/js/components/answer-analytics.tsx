import { Link } from '@inertiajs/react';
import { ArrowUpRight, ListChecks, MessageSquareQuote, Sparkles, Ticket } from 'lucide-react';
import { PALETTE } from '@/components/charts';

interface Bar { name: string; value: number; facet?: string }
interface Base { id: string; label: string; answered: number; total: number; rate: number }
interface ChoiceQuestion extends Base {
    kind: 'choice';
    multiple: boolean;
    options: Bar[];
    by_type: Array<{ type: string; counts: Record<string, number> }>;
}
interface TextQuestion extends Base {
    kind: 'text';
    values: Bar[];
    distinct: number;
    other: number;
    samples: string[];
}
export type AnswerQuestion = ChoiceQuestion | TextQuestion;

/**
 * The organizer's booking questions, counted.
 *
 * Exact numbers first, bars second: the use this was asked for is counting —
 * how many iced lattes, how many first-timers — so every row shows the count
 * and its share outright instead of hiding them behind a chart tooltip. Each
 * row links into the attendee list already filtered to those people, which is
 * the next thing anyone does with a number like "7 vegetarians".
 *
 * Choice questions list every option, zeros included (nobody picking the vegan
 * meal is worth knowing). When an event sells more than one ticket type the
 * answers are also split by type, because "beginners vs experienced session"
 * is the comparison a question like "Have you played before?" exists for.
 *
 * Typed answers are grouped case-insensitively server-side (AnswerInsights);
 * one-off replies are not plotted one bar each but stay visible as examples.
 */
export function AnswerAnalytics({ questions, drillBase }: {
    questions: AnswerQuestion[];
    /** e.g. /host/events/{slug}/attendees — omit to render rows without links. */
    drillBase?: string;
}) {
    if (questions.length === 0) {
        return null;
    }

    const holders = questions[0]?.total ?? 0;

    return (
        <section>
            <div className="mt-8 mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 className="flex items-center gap-2 text-lg font-semibold tracking-tight"><Sparkles className="size-4" /> Booking questions</h2>
                    <p className="text-sm text-muted-foreground">
                        What your {holders} current ticket-holder{holders === 1 ? '' : 's'} answered — counted across everyone attending, not just the selected date range.
                    </p>
                </div>
            </div>

            {holders === 0 ? (
                <div className="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground">
                    Answers appear here once people book tickets.
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {questions.map((q, i) => (
                        <QuestionCard key={q.id} question={q} color={PALETTE[i % PALETTE.length]} drillBase={drillBase} />
                    ))}
                </div>
            )}
        </section>
    );
}

function QuestionCard({ question: q, color, drillBase }: { question: AnswerQuestion; color: string; drillBase?: string }) {
    const bars = q.kind === 'choice' ? q.options : q.values;
    // Shares are of the people who ANSWERED, so a skipped optional question
    // doesn't make every option look less popular than it is.
    const denominator = Math.max(1, q.answered);
    const max = Math.max(1, ...bars.map((b) => b.value));
    const link = (facet?: string) => (drillBase && facet ? `${drillBase}?${new URLSearchParams({ [`facets[f_${q.id}]`]: facet }).toString()}` : null);

    return (
        <article className="flex flex-col rounded-2xl border border-border bg-card p-5 shadow-sm">
            <header className="mb-4">
                <div className="flex items-start justify-between gap-3">
                    <h3 className="min-w-0 break-words text-sm font-semibold">{q.label}</h3>
                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                        {q.kind === 'choice' ? <><ListChecks className="size-3" /> {q.multiple ? 'Multi-choice' : 'Choice'}</> : <><MessageSquareQuote className="size-3" /> Typed answer</>}
                    </span>
                </div>
                {/* Response rate — how complete this picture is. */}
                <div className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                    <div className="h-1.5 w-24 overflow-hidden rounded-full bg-muted">
                        <div className="h-full rounded-full bg-foreground" style={{ width: `${q.rate}%` }} />
                    </div>
                    <span><span className="font-semibold text-foreground">{q.answered}</span> of {q.total} answered ({q.rate}%)</span>
                </div>
                {q.kind === 'choice' && q.multiple && (
                    <p className="mt-1 text-[11px] text-muted-foreground">People could pick more than one, so shares can add up to more than 100%.</p>
                )}
            </header>

            {bars.length === 0 ? (
                <p className="text-sm text-muted-foreground">No answers yet.</p>
            ) : (
                <ul className="grid gap-2">
                    {bars.map((bar) => {
                        const pct = Math.round((bar.value / denominator) * 100);
                        const href = bar.value > 0 ? link(bar.facet) : null;
                        const body = (
                            <>
                                <div className="flex items-baseline justify-between gap-3 text-sm">
                                    <span className="min-w-0 break-words">{bar.name}</span>
                                    <span className="flex shrink-0 items-baseline gap-1.5 tabular-nums">
                                        <span className="font-semibold">{bar.value}</span>
                                        <span className="text-xs text-muted-foreground">{pct}%</span>
                                        {href && <ArrowUpRight className="size-3 self-center text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />}
                                    </span>
                                </div>
                                <div className="mt-1 h-2 overflow-hidden rounded-full bg-muted">
                                    <div className="h-full rounded-full transition-[width]" style={{ width: `${(bar.value / max) * 100}%`, background: color }} />
                                </div>
                            </>
                        );

                        return (
                            <li key={bar.name}>
                                {href ? (
                                    <Link href={href} className="group block rounded-lg px-1 py-0.5 hover:bg-muted/50" title={`See the ${bar.value} attendee${bar.value === 1 ? '' : 's'} who answered “${bar.name}”`}>
                                        {body}
                                    </Link>
                                ) : <div className={`px-1 py-0.5 ${bar.value === 0 ? 'opacity-50' : ''}`}>{body}</div>}
                            </li>
                        );
                    })}
                </ul>
            )}

            {q.kind === 'text' && (q.other > 0 || q.samples.length > 0) && (
                <div className="mt-4 border-t border-border pt-3">
                    {q.other > 0 && (
                        <p className="text-xs text-muted-foreground">
                            +{q.other} other answer{q.other === 1 ? '' : 's'} ({q.distinct} different in total).
                        </p>
                    )}
                    {q.samples.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {q.samples.map((sample) => (
                                <span key={sample} className="max-w-full truncate rounded-full border border-border px-2.5 py-0.5 text-[11px] italic text-muted-foreground">“{sample}”</span>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {q.kind === 'choice' && q.by_type.length > 1 && <ByTicketType question={q} />}
        </article>
    );
}

/** Answers split by ticket type, as a small count table — easy to read across. */
function ByTicketType({ question: q }: { question: ChoiceQuestion }) {
    return (
        <div className="mt-4 border-t border-border pt-3">
            <h4 className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><Ticket className="size-3" /> By ticket type</h4>
            <div className="-mx-1 overflow-x-auto">
                <table className="w-full min-w-[18rem] text-xs">
                    <thead>
                        <tr className="text-muted-foreground">
                            <th className="px-1 py-1 text-left font-medium" />
                            {q.by_type.map((t) => <th key={t.type} className="px-1 py-1 text-right font-medium">{t.type}</th>)}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {q.options.map((option) => (
                            <tr key={option.name}>
                                <td className="px-1 py-1.5">{option.name}</td>
                                {q.by_type.map((t) => (
                                    <td key={t.type} className={`px-1 py-1.5 text-right tabular-nums ${(t.counts[option.name] ?? 0) === 0 ? 'text-muted-foreground' : 'font-semibold'}`}>
                                        {t.counts[option.name] ?? 0}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
