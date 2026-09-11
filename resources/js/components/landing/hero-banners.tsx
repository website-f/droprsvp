import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface Banner { image: string; heading: string; subheading: string; cta_label: string; cta_url: string; align?: 'left' | 'center' | 'right' }

const ALIGN_ITEMS = { left: 'items-start text-left', center: 'items-center text-center', right: 'items-end text-right' } as const;
const ALIGN_GRADIENT = {
    left: 'from-black/70 via-black/35 to-transparent',
    center: 'from-black/55 via-black/35 to-black/55',
    right: 'from-transparent via-black/35 to-black/70',
} as const;

/**
 * Eventbrite-style hero carousel: full-width image banners with a heading +
 * CTA, auto-swiping and swipeable, responsive on every screen.
 */
export function HeroBanners({ banners, autoplay = true, interval = 5 }: { banners: Banner[]; autoplay?: boolean; interval?: number }) {
    const slides = banners.filter((b) => b.image || b.heading);
    const [i, setI] = useState(0);
    const touchX = useRef<number | null>(null);
    const n = slides.length;

    const go = (to: number) => setI(((to % n) + n) % n);

    // Auto-advance (paused when the tab is hidden or there's a single slide).
    useEffect(() => {
        if (!autoplay || n < 2) {
            return;
        }

        const t = setInterval(() => setI((c) => (c + 1) % n), Math.max(2, interval) * 1000);

        return () => clearInterval(t);
    }, [autoplay, interval, n]);

    if (n === 0) {
        return null;
    }

    const isInternal = (url: string) => url.startsWith('/');

    return (
        <section className="mx-auto w-full max-w-6xl px-4 pt-4 sm:px-6 sm:pt-6">
            <div
                className="relative overflow-hidden rounded-2xl"
                onTouchStart={(e) => {
 touchX.current = e.touches[0].clientX; 
}}
                onTouchEnd={(e) => {
                    if (touchX.current === null) {
return;
}

                    const dx = e.changedTouches[0].clientX - touchX.current;

                    if (Math.abs(dx) > 40) {
go(i + (dx < 0 ? 1 : -1));
}

                    touchX.current = null;
                }}
            >
                <div className="flex transition-transform duration-500 ease-out" style={{ transform: `translateX(-${i * 100}%)` }}>
                    {slides.map((b, idx) => {
                        const align = b.align ?? 'center';

                        return (
                            // The aspect ratio is a MINIMUM, not a cap: the copy sits in normal
                            // flow (no h-full), so a long heading grows the banner instead of
                            // being clipped by the parent's overflow-hidden — which is what used
                            // to make desktop headings vanish. `items-center` keeps it centred
                            // whenever the copy is shorter than the image band.
                            <div
                                key={idx}
                                className="relative flex aspect-[16/10] w-full shrink-0 items-center overflow-hidden bg-muted sm:aspect-[1200/420]"
                                style={{ flex: '0 0 100%' }}
                            >
                                {b.image && <img src={b.image} alt={b.heading || ''} className="absolute inset-0 size-full object-cover" loading={idx === 0 ? 'eager' : 'lazy'} />}
                                <div className={`absolute inset-0 bg-gradient-to-r ${ALIGN_GRADIENT[align]}`} />
                                {/* Vertical scrim on top of the horizontal one — a bright photo
                                    would otherwise leave white copy unreadable. */}
                                <div className="absolute inset-0 bg-gradient-to-t from-black/50 via-black/10 to-black/25" />
                                {(b.heading || b.subheading || b.cta_label) && (
                                    // Mobile swipes (arrows are hidden there), so the copy gets
                                    // the full width back — only desktop reserves room for them.
                                    <div className={`relative flex w-full flex-col gap-2.5 px-5 py-8 text-white sm:gap-3.5 sm:py-12 ${n > 1 ? 'sm:px-16' : 'sm:px-10'} ${ALIGN_ITEMS[align]}`}>
                                        {b.heading && <h2 className="max-w-2xl text-balance text-xl font-extrabold uppercase leading-[1.15] tracking-tight sm:text-3xl lg:text-4xl">{b.heading}</h2>}
                                        {b.subheading && <p className="max-w-xl text-pretty text-xs leading-relaxed text-white/85 sm:text-base">{b.subheading}</p>}
                                        {b.cta_label && b.cta_url && (
                                            isInternal(b.cta_url)
                                                ? <Link href={b.cta_url} className="mt-1.5 w-max rounded-full bg-white px-4 py-2 text-xs font-semibold text-black transition-transform hover:scale-[1.03] sm:mt-1 sm:px-5 sm:py-2.5 sm:text-sm">{b.cta_label}</Link>
                                                : <a href={b.cta_url} target="_blank" rel="noopener" className="mt-1.5 w-max rounded-full bg-white px-4 py-2 text-xs font-semibold text-black transition-transform hover:scale-[1.03] sm:mt-1 sm:px-5 sm:py-2.5 sm:text-sm">{b.cta_label}</a>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>

                {n > 1 && (
                    <>
                        {/* Liquid-glass controls — translucent, blurred, kept off the copy.
                            Hidden on mobile: they'd eat ~110px of a ~350px-wide banner, and
                            the slides are swipeable (plus the dots below are tappable). */}
                        <button type="button" aria-label="Previous" onClick={() => go(i - 1)} className="absolute left-3 top-1/2 hidden size-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/25 backdrop-blur-md transition-colors hover:bg-white/25 sm:flex"><ChevronLeft className="size-4" /></button>
                        <button type="button" aria-label="Next" onClick={() => go(i + 1)} className="absolute right-3 top-1/2 hidden size-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/25 backdrop-blur-md transition-colors hover:bg-white/25 sm:flex"><ChevronRight className="size-4" /></button>
                        {/* Dots: small, glassy, tucked in the bottom-right so they never sit over the heading/CTA. */}
                        <div className="absolute bottom-3 right-3 flex items-center gap-1 rounded-full bg-white/10 px-2 py-1.5 ring-1 ring-white/20 backdrop-blur-md">
                            {slides.map((_, d) => (
                                <button key={d} type="button" aria-label={`Slide ${d + 1}`} onClick={() => go(d)} className={`h-1 rounded-full transition-all ${d === i ? 'w-4 bg-white' : 'w-1 bg-white/50 hover:bg-white/80'}`} />
                            ))}
                        </div>
                    </>
                )}
            </div>
        </section>
    );
}
