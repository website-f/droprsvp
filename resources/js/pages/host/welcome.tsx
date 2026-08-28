import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Check } from 'lucide-react';
import { useState } from 'react';
import { Wordmark } from '@/components/brand';
import { Button } from '@/components/ui/button';

interface Profile { event_types: string[] | null; revenue_band: string | null; events_per_year: string | null; audience_size: string | null; age_range: string | null }

type QKey = 'event_types' | 'revenue_band' | 'events_per_year' | 'audience_size' | 'age_range';
interface Question { key: QKey; multi?: boolean; title: string; subtitle: string; options: string[] }

const QUESTIONS: Question[] = [
    { key: 'event_types', multi: true, title: 'What kind of events do you run?', subtitle: 'Pick all that apply.', options: ['Music', 'Business', 'Food & Drink', 'Tech', 'Community', 'Sports', 'Arts', 'Wellness'] },
    { key: 'events_per_year', title: 'How many events in the next 12 months?', subtitle: 'A rough estimate is fine.', options: ['Just 1', '2–5', '6–12', '12+'] },
    { key: 'audience_size', title: 'How big is your typical crowd?', subtitle: 'Helps us tailor seating tools.', options: ['Under 50', '50–200', '200–1,000', '1,000+'] },
    { key: 'revenue_band', title: 'Expected ticket revenue?', subtitle: 'Optional — only you see this.', options: ['Just starting', 'Under RM10k', 'RM10k–50k', 'RM50k–200k', 'RM200k+'] },
    { key: 'age_range', title: 'Typical attendee age?', subtitle: 'Helps with recommendations.', options: ['18–24', '25–34', '35–44', '45+', 'Mixed'] },
];

type Answers = { event_types: string[]; revenue_band: string; events_per_year: string; audience_size: string; age_range: string };

export default function OrganizerWelcome({ profile }: { profile: Profile | null }) {
    const [step, setStep] = useState(0);
    const [saving, setSaving] = useState(false);
    const [answers, setAnswers] = useState<Answers>({
        event_types: profile?.event_types ?? [],
        revenue_band: profile?.revenue_band ?? '',
        events_per_year: profile?.events_per_year ?? '',
        audience_size: profile?.audience_size ?? '',
        age_range: profile?.age_range ?? '',
    });

    const q = QUESTIONS[step];
    const isLast = step === QUESTIONS.length - 1;

    const toggleMulti = (opt: string) => setAnswers((a) => ({
        ...a,
        event_types: a.event_types.includes(opt) ? a.event_types.filter((x) => x !== opt) : [...a.event_types, opt],
    }));
    const selected = (opt: string) => q.multi ? answers.event_types.includes(opt) : answers[q.key] === opt;
    const pick = (opt: string) => q.multi ? toggleMulti(opt) : setAnswers((a) => ({ ...a, [q.key]: opt }));

    const next = () => {
 if (isLast) {
finish();
} else {
setStep((s) => s + 1);
} 
};
    const finish = () => {
 setSaving(true); router.post('/host/welcome', answers, { onFinish: () => setSaving(false) }); 
};

    return (
        <>
            <Head title="Welcome to DropRSVP" />
            <div className="flex min-h-screen flex-col bg-muted/30">
                <header className="flex items-center justify-between px-6 py-5">
                    <Wordmark className="h-8" />
                    <Link href="/dashboard" className="text-sm text-muted-foreground hover:text-foreground">Skip for now</Link>
                </header>

                <div className="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-6 pb-16">
                    {/* progress */}
                    <div className="mb-8 flex items-center gap-2">
                        {QUESTIONS.map((_, i) => <div key={i} className={`h-1.5 flex-1 rounded-full ${i <= step ? 'bg-foreground' : 'bg-border'}`} />)}
                    </div>

                    {step === 0 && (
                        <p className="mb-6 text-sm font-medium uppercase tracking-wide text-muted-foreground">Welcome aboard 🎉 A few quick questions (all optional)</p>
                    )}

                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">{q.title}</h1>
                    <p className="mt-1.5 text-sm text-muted-foreground">{q.subtitle}</p>

                    <div className={`mt-6 grid gap-3 ${q.multi ? 'grid-cols-2 sm:grid-cols-3' : 'sm:grid-cols-2'}`}>
                        {q.options.map((opt) => (
                            <button
                                key={opt}
                                type="button"
                                onClick={() => pick(opt)}
                                className={`flex items-center justify-between gap-2 rounded-xl border p-4 text-left text-sm font-medium transition-all ${selected(opt) ? 'border-foreground bg-foreground text-background' : 'border-border bg-card hover:border-foreground/40'}`}
                            >
                                {opt}
                                {selected(opt) && <Check className="size-4 shrink-0" />}
                            </button>
                        ))}
                    </div>

                    <div className="mt-8 flex items-center justify-between">
                        <button type="button" onClick={() => setStep((s) => Math.max(0, s - 1))} disabled={step === 0} className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground disabled:opacity-0"><ArrowLeft className="size-4" /> Back</button>
                        <div className="flex items-center gap-2">
                            <Button variant="ghost" onClick={next}>Skip</Button>
                            <Button onClick={next} disabled={saving}>{isLast ? (saving ? 'Finishing…' : 'Finish') : 'Continue'}</Button>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
