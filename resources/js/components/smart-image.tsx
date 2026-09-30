import { useState } from 'react';

/**
 * Images that appear fast, and are never cropped when they shouldn't be.
 *
 * Two problems this solves, both reported from production:
 *
 *  1. Galleries took seconds to paint. Every tile was handed the full upload —
 *     up to 2000px and a few hundred kilobytes — to draw into a ~200px square.
 *     Uploads now also get a 640px copy under `thumbs/` (see
 *     App\Support\ImageOptimizer), and grids ask for that instead. Images from
 *     before that shipped have no thumbnail, so a failed load silently retries
 *     the original: nothing ever 404s its way to a broken tile.
 *
 *  2. Posters were cropped to death. Organizers upload the artwork they already
 *     have, which is very often an Instagram square or a 9:16 story. `object-cover`
 *     in a 16/9 card threw away most of it, including the text. `<Framed>` keeps
 *     the whole image (object-contain) and fills the leftover space with a
 *     blurred, scaled copy of the image itself, so the card keeps its shape in
 *     the grid while the artwork stays intact whatever its ratio.
 */

/** `/storage/cms/x.jpg` → `/storage/cms/thumbs/x.jpg`. Non-upload URLs are left alone. */
export function thumbUrl(src: string): string {
    // Only our own uploads have thumbnails. An absolute URL to somebody else's
    // host, a data: URI, or an asset shipped in /public must pass through
    // untouched or it would 404 on every load and always fall back.
    if (!src || !src.includes('/storage/')) {
        return src;
    }

    const cut = src.lastIndexOf('/');

    return cut === -1 ? src : `${src.slice(0, cut)}/thumbs${src.slice(cut)}`;
}

interface BaseProps {
    src: string;
    alt: string;
    className?: string;
    /** First-screen images should not be lazy — it delays the one thing people wait for. */
    eager?: boolean;
    onClick?: (e: React.MouseEvent<HTMLImageElement>) => void;
}

/**
 * An `<img>` that prefers the thumbnail and falls back to the original.
 *
 * Use for anything drawn small (grid tiles, avatars, card art). Full-size views
 * — the lightbox, the event banner — should use a plain `<img>` with the
 * original, because that is the whole point of opening them.
 */
export function SmartImage({ src, alt, className, eager, onClick }: BaseProps) {
    const [source, setSource] = useState(() => thumbUrl(src));

    // A new src (carousel, filter change) must reset the fallback state, or the
    // element keeps showing the previous image's resolved URL.
    const [seen, setSeen] = useState(src);

    if (seen !== src) {
        setSeen(src);
        setSource(thumbUrl(src));
    }

    return (
        <img
            src={source}
            alt={alt}
            className={className}
            onClick={onClick}
            loading={eager ? 'eager' : 'lazy'}
            // Off the main thread, so a grid of them doesn't block paint.
            decoding="async"
            fetchPriority={eager ? 'high' : 'auto'}
            // Legacy uploads have no thumbnail. Ask for the original once;
            // guarded so a genuinely missing image can't loop.
            onError={() => {
                if (source !== src) {
                    setSource(src);
                }
            }}
        />
    );
}

/**
 * A fixed-shape frame that shows the whole image, whatever shape it is.
 *
 * The blurred backdrop is the same file the foreground uses (so it is already
 * in cache and costs nothing extra) blown up and blurred to fill the gaps a
 * portrait or square poster leaves in a landscape card.
 */
export function Framed({
    src,
    alt,
    /** Tailwind aspect utility for the frame, e.g. "aspect-[16/9]". */
    ratio = 'aspect-[16/9]',
    className = '',
    eager,
}: BaseProps & { ratio?: string }) {
    const [source, setSource] = useState(() => thumbUrl(src));
    const [seen, setSeen] = useState(src);

    if (seen !== src) {
        setSeen(src);
        setSource(thumbUrl(src));
    }

    const onError = () => {
        if (source !== src) {
            setSource(src);
        }
    };

    return (
        <div className={`relative overflow-hidden bg-muted ${ratio} ${className}`}>
            {/* Backdrop. aria-hidden + empty alt: it carries no information the
                foreground image doesn't already. */}
            <img
                src={source}
                alt=""
                aria-hidden="true"
                onError={onError}
                loading={eager ? 'eager' : 'lazy'}
                decoding="async"
                className="absolute inset-0 size-full scale-110 object-cover blur-xl saturate-150"
            />
            <img
                src={source}
                alt={alt}
                onError={onError}
                loading={eager ? 'eager' : 'lazy'}
                decoding="async"
                fetchPriority={eager ? 'high' : 'auto'}
                className="absolute inset-0 size-full object-contain"
            />
        </div>
    );
}
