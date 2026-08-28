import { cn } from '@/lib/utils';

/**
 * DropRSVP brand marks. Two assets in /public:
 *  - logo-full.png  → horizontal wordmark (headers, footers, auth)
 *  - logo-mark.png  → square icon (collapsed sidebar, favicon, compact spots)
 *
 * The logos are monochrome dark, so on dark surfaces we `invert` them. Use
 * `onDark` for surfaces that are dark in every theme (e.g. a coloured panel);
 * otherwise they invert automatically in dark mode.
 */

export function Wordmark({ className, onDark = false }: { className?: string; onDark?: boolean }) {
    return <img src="/logo-full.png" alt="DropRSVP" className={cn('h-7 w-auto object-contain', onDark ? 'invert' : 'dark:invert', className)} />;
}

export function LogoMark({ className, onDark = false }: { className?: string; onDark?: boolean }) {
    return <img src="/logo-mark.png" alt="DropRSVP" className={cn('size-8 object-contain', onDark ? 'invert' : 'dark:invert', className)} />;
}
