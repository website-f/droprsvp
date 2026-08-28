import { Link } from '@inertiajs/react';
import {
    Briefcase, Cpu, Dumbbell, HeartPulse, Music2, Palette, Sparkles, Tag, Users2, UtensilsCrossed
    
} from 'lucide-react';
import type {LucideIcon} from 'lucide-react';
import type { CSSProperties } from 'react';
import { Reveal } from '@/components/reveal';

/** Distinct, non-generic icon per category (keyed by slug), with a fallback. */
const ICONS: Record<string, LucideIcon> = {
    music: Music2,
    business: Briefcase,
    'food-drink': UtensilsCrossed,
    tech: Cpu,
    community: Users2,
    sports: Dumbbell,
    arts: Palette,
    wellness: HeartPulse,
};

const BLURB: Record<string, string> = {
    music: 'Gigs & live sets',
    business: 'Talks & networking',
    'food-drink': 'Tastings & festivals',
    tech: 'Meetups & demos',
    community: 'Local get-togethers',
    sports: 'Games & fitness',
    arts: 'Shows & workshops',
    wellness: 'Yoga & retreats',
};

/** A distinct accent per category — a lively palette built around the unDraw violet. */
const ACCENT: Record<string, string> = {
    music: '#6c63ff',
    business: '#2ec4b6',
    'food-drink': '#f5a524',
    tech: '#3b82f6',
    community: '#ff6584',
    sports: '#f97316',
    arts: '#a855f7',
    wellness: '#22c55e',
};
const FALLBACK_ACCENT = '#6c63ff';

export function CategoryGrid({ categories }: { categories: { name: string; slug: string }[] }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
            {categories.map((c, i) => {
                const Icon = ICONS[c.slug] ?? Tag;
                const accent = ACCENT[c.slug] ?? FALLBACK_ACCENT;

                return (
                    <Reveal key={c.slug} delay={i * 45}>
                        <Link
                            href={`/en-my/all/${c.slug}`}
                            className="group flex h-full items-center gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-[color:var(--accent)] hover:shadow-md"
                            style={{ '--accent': accent } as CSSProperties}
                        >
                            <span
                                className="flex size-11 shrink-0 items-center justify-center rounded-xl transition-transform duration-300 group-hover:scale-105"
                                style={{ backgroundColor: `${accent}1f`, color: accent }}
                            >
                                <Icon className="size-5" />
                            </span>
                            <span className="min-w-0">
                                <span className="block truncate text-sm font-semibold">{c.name}</span>
                                <span className="block truncate text-xs text-muted-foreground">{BLURB[c.slug] ?? 'Explore events'}</span>
                            </span>
                        </Link>
                    </Reveal>
                );
            })}
        </div>
    );
}

/** A small, decorative marker used in the "how it works" band. */
export const StepSparkle = Sparkles;
