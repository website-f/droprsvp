import { Link } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import type { CSSProperties } from 'react';
import { Reveal } from '@/components/reveal';
import { categoryIcon } from '@/lib/category-icons';

const FALLBACK_ACCENT = '#6c63ff';

export interface CategoryTile { name: string; slug: string; icon?: string | null; blurb?: string | null; color?: string | null }

export function CategoryGrid({ categories }: { categories: CategoryTile[] }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
            {categories.map((c, i) => {
                const Icon = categoryIcon(c.icon);
                const accent = c.color || FALLBACK_ACCENT;

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
                                <span className="block truncate text-xs text-muted-foreground">{c.blurb || 'Explore events'}</span>
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
