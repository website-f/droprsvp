import { Link } from '@inertiajs/react';
import {
    Briefcase, Cpu, Dumbbell, HeartPulse, Music2, Palette, Sparkles, Tag, Users2, UtensilsCrossed,
    type LucideIcon,
} from 'lucide-react';
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

export function CategoryGrid({ categories }: { categories: { name: string; slug: string }[] }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
            {categories.map((c, i) => {
                const Icon = ICONS[c.slug] ?? Tag;
                return (
                    <Reveal key={c.slug} delay={i * 45}>
                        <Link
                            href={`/events?category=${c.slug}`}
                            className="group flex h-full items-center gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-foreground/40 hover:shadow-md"
                        >
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-secondary text-foreground transition-colors duration-300 group-hover:bg-foreground group-hover:text-background">
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
