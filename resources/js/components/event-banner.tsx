import { Expand, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * The event banner, shown at whatever shape it was uploaded in.
 *
 * It used to be locked to aspect-[3/1] (4/1 on desktop) and cropped to fill, on
 * the assumption that every banner is a wide strip. Organizers upload the
 * artwork they already have — very often an Instagram-story graphic at 9:16 —
 * and object-cover threw away most of it, including the text, which is the whole
 * point of a poster.
 *
 * So the image keeps its own ratio and is contained rather than cropped. The
 * only constraint is a max height, so a tall poster can't push the entire page
 * below the fold; when it hits that ceiling the full thing is one tap away in
 * the fullscreen view.
 */
export function EventBanner({ src, alt }: { src: string; alt: string }) {
    const [full, setFull] = useState(false);

    // Escape closes the lightbox, and the page must not scroll behind it.
    useEffect(() => {
        if (!full) {
            return;
        }

        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setFull(false);
        const previous = document.body.style.overflow;

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);

        return () => {
            document.body.style.overflow = previous;
            window.removeEventListener('keydown', onKey);
        };
    }, [full]);

    return (
        <>
            <div className="group relative overflow-hidden rounded-2xl border border-border bg-muted/30">
                <img
                    src={src}
                    alt={alt}
                    // h-auto + object-contain: the element takes the image's own
                    // shape instead of forcing the image into the element's.
                    className="mx-auto block h-auto max-h-[70vh] w-full object-contain"
                />

                <button
                    type="button"
                    onClick={() => setFull(true)}
                    aria-label="View banner full screen"
                    className="absolute right-3 top-3 flex items-center gap-1.5 rounded-lg bg-black/60 px-2.5 py-1.5 text-xs font-medium text-white backdrop-blur-sm transition-opacity hover:bg-black/75 focus-visible:opacity-100 md:opacity-0 md:group-hover:opacity-100"
                >
                    <Expand className="size-3.5" /> Full screen
                </button>
            </div>

            {full && (
                <div
                    className="fixed inset-0 z-[70] flex items-center justify-center bg-black/95 p-4"
                    onClick={() => setFull(false)}
                    role="dialog"
                    aria-modal="true"
                    aria-label={alt}
                >
                    <button
                        type="button"
                        onClick={() => setFull(false)}
                        aria-label="Close"
                        className="absolute right-4 top-4 grid size-10 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20"
                    >
                        <X className="size-5" />
                    </button>

                    {/* Stop propagation so tapping the poster itself doesn't close it —
                        people pinch-zoom here, and a stray tap shouldn't dismiss. */}
                    <img
                        src={src}
                        alt={alt}
                        onClick={(e) => e.stopPropagation()}
                        className="max-h-full max-w-full object-contain"
                    />
                </div>
            )}
        </>
    );
}
