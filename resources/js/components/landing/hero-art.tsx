import { CalendarDays, MapPin, Music, Ticket } from 'lucide-react';

/**
 * Decorative, hand-built hero artwork — a soft dotted field, a faint concentric
 * ring, and a few floating "chips" that echo the product (ticket, date, place,
 * music). Purely ambient: aria-hidden and non-interactive. Monochrome so it
 * reads in both light and dark themes.
 */
export function HeroArt() {
    return (
        <div aria-hidden className="pointer-events-none absolute inset-0 overflow-hidden">
            {/* Texture: dotted field + concentric rings, fading out toward the centre */}
            <svg className="absolute inset-0 h-full w-full text-foreground" preserveAspectRatio="xMidYMid slice">
                <defs>
                    <pattern id="drsvp-dots" width="26" height="26" patternUnits="userSpaceOnUse">
                        <circle cx="1.5" cy="1.5" r="1.5" fill="currentColor" />
                    </pattern>
                    <radialGradient id="drsvp-fade" cx="50%" cy="38%" r="70%">
                        <stop offset="0%" stopColor="white" stopOpacity="0" />
                        <stop offset="55%" stopColor="white" stopOpacity="0.7" />
                        <stop offset="100%" stopColor="white" stopOpacity="1" />
                    </radialGradient>
                    <mask id="drsvp-mask">
                        <rect width="100%" height="100%" fill="url(#drsvp-fade)" />
                    </mask>
                </defs>
                <g mask="url(#drsvp-mask)" opacity="0.06">
                    <rect width="100%" height="100%" fill="url(#drsvp-dots)" />
                </g>
                <g fill="none" stroke="currentColor" strokeOpacity="0.05" strokeWidth="1.5">
                    <circle cx="50%" cy="26%" r="150" />
                    <circle cx="50%" cy="26%" r="260" />
                    <circle cx="50%" cy="26%" r="380" />
                </g>
            </svg>

            {/* Colourful ambient glows — bring the page to life without hurting legibility */}
            <div className="absolute left-1/2 top-[12%] size-[36rem] -translate-x-1/2 rounded-full bg-foreground/[0.03] blur-3xl" />
            <div className="absolute -left-24 top-[8%] size-72 rounded-full opacity-30 blur-3xl sm:opacity-40" style={{ background: 'radial-gradient(circle,#6c63ff,transparent 70%)' }} />
            <div className="absolute -right-20 top-[2%] size-72 rounded-full opacity-25 blur-3xl sm:opacity-35" style={{ background: 'radial-gradient(circle,#ff6584,transparent 70%)' }} />
            <div className="absolute bottom-0 left-1/3 size-64 rounded-full opacity-20 blur-3xl sm:opacity-30" style={{ background: 'radial-gradient(circle,#2ec4b6,transparent 70%)' }} />

            {/* Floating chips — desktop only, kept off mobile to avoid clutter */}
            <div className="hidden lg:block">
                <Chip className="left-[8%] top-[22%] drsvp-float"><Ticket className="size-4" /> 2 tickets</Chip>
                <Chip className="right-[9%] top-[16%] drsvp-float-slow"><CalendarDays className="size-4" /> Sat, 8 PM</Chip>
                <Chip className="left-[12%] top-[54%] drsvp-float-slow"><MapPin className="size-4" /> Bangsar, KL</Chip>
                <Chip className="right-[11%] top-[52%] drsvp-float"><Music className="size-4" /> Live set</Chip>
            </div>
        </div>
    );
}

function Chip({ children, className }: { children: React.ReactNode; className?: string }) {
    return (
        <div className={`absolute flex items-center gap-2 rounded-full border border-border bg-card/80 px-3.5 py-2 text-sm font-medium shadow-sm backdrop-blur ${className ?? ''}`}>
            {children}
        </div>
    );
}
