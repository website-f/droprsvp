import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Section, StatusPill } from '@/components/edm/ui';

export interface SpamResult {
    score: number;
    level: 'good' | 'fair' | 'poor';
    checks: { id: string; group: string; label: string; status: 'pass' | 'warn' | 'fail'; detail: string }[];
}

const RING: Record<SpamResult['level'], string> = {
    good: '#16a34a',
    fair: '#d97706',
    poor: '#e11d48',
};

/**
 * The pre-send spam check: a score out of ten and every finding, problems
 * first, each saying what to change. Passing checks fold away so the list
 * reads as a to-do, not a wall.
 */
export function SpamCheckCard({ result }: { result: SpamResult }) {
    const [showPassing, setShowPassing] = useState(false);
    const problems = result.checks.filter((c) => c.status !== 'pass').sort((a, b) => (a.status === 'fail' ? -1 : 1) - (b.status === 'fail' ? -1 : 1));
    const passing = result.checks.filter((c) => c.status === 'pass');
    const color = RING[result.level];
    const deg = Math.round((result.score / 10) * 360);

    return (
        <Section
            title={<span className="flex items-center gap-2"><ShieldCheck className="size-4" /> Spam check</span>}
            description="What spam filters look for, checked against the saved subject and design."
        >
            <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
                <div className="flex shrink-0 items-center gap-3 sm:flex-col sm:items-center">
                    <div
                        className="relative flex size-20 items-center justify-center rounded-full"
                        style={{ background: `conic-gradient(${color} ${deg}deg, var(--muted) ${deg}deg)` }}
                        aria-label={`Score ${result.score} out of 10`}
                    >
                        <div className="flex size-[66px] flex-col items-center justify-center rounded-full bg-card">
                            <span className="text-xl font-bold tabular-nums" style={{ color }}>{result.score}</span>
                            <span className="text-[10px] text-muted-foreground">of 10</span>
                        </div>
                    </div>
                    <StatusPill status={result.level} />
                </div>

                <div className="min-w-0 flex-1">
                    {problems.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Nothing to fix. This reads like mail people asked for.</p>
                    ) : (
                        <ul className="grid gap-2">
                            {problems.map((c) => (
                                <li key={c.id} className="flex items-start gap-2.5 rounded-xl border border-border p-3">
                                    <StatusPill status={c.status} />
                                    <div className="min-w-0 text-sm">
                                        <div className="font-medium">{c.group} · {c.label}</div>
                                        <div className="text-xs text-muted-foreground">{c.detail}</div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    <button type="button" onClick={() => setShowPassing((v) => !v)} className="mt-3 text-xs font-medium text-muted-foreground hover:text-foreground">
                        {showPassing ? 'Hide' : 'Show'} {passing.length} passing check{passing.length === 1 ? '' : 's'}
                    </button>
                    {showPassing && (
                        <ul className="mt-2 grid gap-1">
                            {passing.map((c) => (
                                <li key={c.id} className="flex items-start gap-2 text-xs">
                                    <StatusPill status="pass" />
                                    <span className="min-w-0"><span className="font-medium">{c.label}</span> — <span className="text-muted-foreground">{c.detail}</span></span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </Section>
    );
}
