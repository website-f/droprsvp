import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

const DEFAULT_FULL = '/logo-full.png';
const DEFAULT_MARK = '/logo-mark.png';

/**
 * A logo that never shows as a broken image.
 *
 * If the uploaded logo cannot be loaded (deleted from the Media Library, a
 * moved file, a bad link) it falls back to the built-in DropRSVP logo, and if
 * even that fails it shows the name as text — rather than the browser's
 * broken-image icon with "DropRSVP" beside it in the header.
 */
function BrandImage({ src, fallback, className, style }: { src: string; fallback: string; className?: string; style?: React.CSSProperties }) {
    const [failed, setFailed] = useState<string[]>([]);
    const current = [src, fallback].find((url) => !failed.includes(url));

    if (!current) {
        return <span className={cn('inline-flex items-center font-bold tracking-tight', className)} style={style ? { ...style, width: 'auto', fontSize: style.height ? Number(style.height) * 0.55 : undefined } : undefined}>DropRSVP</span>;
    }

    return <img key={current} src={current} alt="DropRSVP" style={style} className={className} onError={() => setFailed((f) => [...f, current])} />;
}

/** Shared brand marks — logo image + dark-mode inversion come from Admin → Branding. */
function useBranding() {
    const b = usePage().props.branding;

    return {
        full: b?.logo_full || DEFAULT_FULL,
        mark: b?.logo_mark || DEFAULT_MARK,
        invert: b?.invert_dark ?? true,
    };
}

export function Wordmark({ className, onDark = false, height }: { className?: string; onDark?: boolean; height?: number }) {
    const { full, invert } = useBranding();
    const invertCls = invert ? (onDark ? 'invert' : 'dark:invert') : '';

    return <BrandImage src={full} fallback={DEFAULT_FULL} style={height ? { height } : undefined} className={cn('w-auto object-contain', height ? undefined : 'h-7', invertCls, className)} />;
}

export function LogoMark({ className, onDark = false, height }: { className?: string; onDark?: boolean; height?: number }) {
    const { mark, invert } = useBranding();
    const invertCls = invert ? (onDark ? 'invert' : 'dark:invert') : '';

    return <BrandImage src={mark} fallback={DEFAULT_MARK} style={height ? { height, width: height } : undefined} className={cn('object-contain', height ? undefined : 'size-8', invertCls, className)} />;
}
