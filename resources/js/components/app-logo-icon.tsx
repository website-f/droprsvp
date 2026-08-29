import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/** The DropRSVP square mark (from Admin → Branding). Accepts sizing via `className`. */
export default function AppLogoIcon({ className }: { className?: string }) {
    const b = usePage().props.branding;
    const src = b?.logo_mark || '/logo-mark.png';
    const invert = (b?.invert_dark ?? true) ? 'dark:invert' : '';

    return <img src={src} alt="DropRSVP" className={cn('object-contain', invert, className)} />;
}
