import { Expand, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * An event's artwork, shown at whatever shape it was uploaded in.
 *
 * Used for BOTH image slots. They were locked to fixed ratios and cropped to
 * fill — the banner to 3/1, the cover to 16/6 — on the assumption that event
 * art is always a wide strip. Organizers upload the poster they already have,
 * very often an Instagram-story graphic, and object-cover threw away most of it
 * including the text, which is the whole point of a poster.
 *
 * (Fixing only the banner was not enough: an event with no banner shows its
 * cover, so the cropping simply moved to the other slot.)
 *
 * The image now keeps its own ratio and is contained rather than cropped. The
 * only constraint is a max height, so a tall poster can't push the entire page
 * below the fold; when it hits that ceiling the whole thing is one tap away in
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
            <div className="relative overflow-hidden rounded-2xl border border-border bg-muted/30">
                {/* The image itself opens the lightbox — that is the obvious
                    gesture on a poster, and it makes the control discoverable
                    even for anyone who reads the chip as decoration. */}
                <button
                    type="button"
                    onClick={() => setFull(true)}
                    aria-label={`View ${alt} full screen`}
                    className="block w-full cursor-zoom-in"
                >
                    <img
                        src={src}
                        alt={alt}
                        // h-auto + object-contain: the element takes the image's
                        // own shape instead of forcing the image into the
                        // element's.
                        className="mx-auto block h-auto max-h-[70vh] w-full object-contain"
                    />
                </button>

                {/* Always visible. This was hover-only on desktop, which meant
                    nobody found it — a control you have to discover by accident
                    may as well not exist. */}
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute right-3 top-3 flex items-center gap-1.5 rounded-lg bg-black/65 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm backdrop-blur-sm"
                >
                    <Expand className="size-3.5" /> Full screen
                </span>
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
