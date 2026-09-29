import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Fullscreen image viewer for a gallery.
 *
 * Gallery thumbnails were square-cropped `<img>` tags with no click handler, so
 * on a phone there was no way to see a photo properly — the crop was all you
 * got. This opens the full image and lets you move through the set.
 *
 * Built for touch first, since that is where it was reported:
 *   * swipe left/right to move between images,
 *   * arrow keys and on-screen buttons for everyone else,
 *   * Escape to close, and the backdrop is a tap target too,
 *   * the page behind is scroll-locked, so a swipe never drags the page,
 *   * a counter, because a lightbox with no position is disorienting.
 */
export function ImageLightbox({
    images,
    index,
    onClose,
    onIndexChange,
}: {
    images: Array<{ src: string; alt?: string }>;
    /** null = closed. */
    index: number | null;
    onClose: () => void;
    onIndexChange: (index: number) => void;
}) {
    const open = index !== null;
    const count = images.length;

    const go = useCallback(
        (delta: number) => {
            if (index === null || count === 0) {
                return;
            }
            // Wrap, so the last image's "next" is the first rather than a dead end.
            onIndexChange((index + delta + count) % count);
        },
        [index, count, onIndexChange],
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
            }
            if (e.key === 'ArrowRight') {
                go(1);
            }
            if (e.key === 'ArrowLeft') {
                go(-1);
            }
        };

        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);

        return () => {
            document.body.style.overflow = previous;
            window.removeEventListener('keydown', onKey);
        };
    }, [open, go, onClose]);

    // Swipe. Tracked manually rather than with a library: it is one axis and a
    // threshold, and the page is already scroll-locked so there is nothing to
    // disambiguate against.
    const touchStart = useRef<{ x: number; y: number } | null>(null);

    const onTouchStart = (e: React.TouchEvent) => {
        touchStart.current = { x: e.touches[0].clientX, y: e.touches[0].clientY };
    };

    const onTouchEnd = (e: React.TouchEvent) => {
        if (!touchStart.current) {
            return;
        }

        const dx = e.changedTouches[0].clientX - touchStart.current.x;
        const dy = e.changedTouches[0].clientY - touchStart.current.y;

        touchStart.current = null;

        // Ignore mostly-vertical drags — that is a dismiss gesture or a
        // pinch-zoom pan, not a request for the next photo.
        if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) {
            return;
        }

        go(dx < 0 ? 1 : -1);
    };

    if (!open || count === 0) {
        return null;
    }

    const current = images[index];

    return (
        <div
            className="fixed inset-0 z-[80] flex items-center justify-center bg-black/95"
            role="dialog"
            aria-modal="true"
            aria-label={current.alt || `Image ${index + 1} of ${count}`}
            onClick={onClose}
            onTouchStart={onTouchStart}
            onTouchEnd={onTouchEnd}
        >
            <button
                type="button"
                onClick={onClose}
                aria-label="Close"
                className="absolute right-4 top-4 z-10 grid size-10 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20"
            >
                <X className="size-5" />
            </button>

            {count > 1 && (
                <span className="absolute left-1/2 top-5 z-10 -translate-x-1/2 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-white">
                    {index + 1} / {count}
                </span>
            )}

            {count > 1 && (
                <>
                    <NavButton side="left" onClick={() => go(-1)} />
                    <NavButton side="right" onClick={() => go(1)} />
                </>
            )}

            {/* Tapping the photo itself must not close it — people tap to zoom. */}
            <img
                src={current.src}
                alt={current.alt ?? ''}
                onClick={(e) => e.stopPropagation()}
                className="max-h-full max-w-full object-contain p-4"
            />
        </div>
    );
}

function NavButton({ side, onClick }: { side: 'left' | 'right'; onClick: () => void }) {
    const Icon = side === 'left' ? ChevronLeft : ChevronRight;

    return (
        <button
            type="button"
            aria-label={side === 'left' ? 'Previous image' : 'Next image'}
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
            // Hidden on the smallest screens where swiping is the natural gesture
            // and the buttons would sit on top of the photo.
            className={`absolute top-1/2 z-10 hidden size-11 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 sm:grid ${
                side === 'left' ? 'left-4' : 'right-4'
            }`}
        >
            <Icon className="size-6" />
        </button>
    );
}

/** Convenience state for a gallery: `const g = useLightbox()`. */
export function useLightbox() {
    const [index, setIndex] = useState<number | null>(null);

    return {
        index,
        open: (i: number) => setIndex(i),
        close: () => setIndex(null),
        change: (i: number) => setIndex(i),
    };
}
