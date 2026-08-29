import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/** Shared brand marks — logo image + dark-mode inversion come from Admin → Branding. */
function useBranding() {
    const b = usePage().props.branding;

    return {
        full: b?.logo_full || '/logo-full.png',
        mark: b?.logo_mark || '/logo-mark.png',
        invert: b?.invert_dark ?? true,
    };
}

export function Wordmark({ className, onDark = false, height }: { className?: string; onDark?: boolean; height?: number }) {
    const { full, invert } = useBranding();
    const invertCls = invert ? (onDark ? 'invert' : 'dark:invert') : '';

    return <img src={full} alt="DropRSVP" style={height ? { height } : undefined} className={cn('w-auto object-contain', height ? undefined : 'h-7', invertCls, className)} />;
}

export function LogoMark({ className, onDark = false, height }: { className?: string; onDark?: boolean; height?: number }) {
    const { mark, invert } = useBranding();
    const invertCls = invert ? (onDark ? 'invert' : 'dark:invert') : '';

    return <img src={mark} alt="DropRSVP" style={height ? { height, width: height } : undefined} className={cn('object-contain', height ? undefined : 'size-8', invertCls, className)} />;
}
