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
                        const content = (
                            <div className="relative aspect-[16/9] w-full shrink-0 overflow-hidden bg-muted sm:aspect-[1200/420]" style={{ flex: '0 0 100%' }}>
                                {b.image && <img src={b.image} alt={b.heading || ''} className="absolute inset-0 size-full object-cover" loading={idx === 0 ? 'eager' : 'lazy'} />}
                                <div className={`absolute inset-0 bg-gradient-to-r ${ALIGN_GRADIENT[align]}`} />
                                {(b.heading || b.subheading || b.cta_label) && (
                                    // Extra horizontal room when the prev/next arrows are shown so
                                    // left/right-aligned copy never sits under them.
                                    <div className={`relative flex h-full flex-col justify-center gap-3 py-6 text-white sm:py-10 ${n > 1 ? 'px-14 sm:px-16' : 'px-6 sm:px-10'} ${ALIGN_ITEMS[align]}`}>
                                        {b.heading && <h2 className="max-w-2xl text-2xl font-extrabold uppercase leading-tight tracking-tight sm:text-4xl">{b.heading}</h2>}
                                        {b.subheading && <p className="max-w-xl text-sm text-white/85 sm:text-base">{b.subheading}</p>}
                                        {b.cta_label && b.cta_url && (
                                            isInternal(b.cta_url)
                                                ? <Link href={b.cta_url} className="mt-1 w-max rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-black transition-transform hover:scale-[1.03]">{b.cta_label}</Link>
                                                : <a href={b.cta_url} target="_blank" rel="noopener" className="mt-1 w-max rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-black transition-transform hover:scale-[1.03]">{b.cta_label}</a>
                                        )}
                                    </div>
                                )}
                            </div>
                        );

                        return <div key={idx} className="w-full shrink-0" style={{ flex: '0 0 100%' }}>{content}</div>;
                    })}
                </div>

                {n > 1 && (
                    <>
                        {/* Liquid-glass controls — translucent, blurred, kept off the copy. */}
                        <button type="button" aria-label="Previous" onClick={() => go(i - 1)} className="absolute left-3 top-1/2 flex size-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/25 backdrop-blur-md transition-colors hover:bg-white/25"><ChevronLeft className="size-4" /></button>
                        <button type="button" aria-label="Next" onClick={() => go(i + 1)} className="absolute right-3 top-1/2 flex size-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/25 backdrop-blur-md transition-colors hover:bg-white/25"><ChevronRight className="size-4" /></button>
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
