import { cn } from '@/lib/utils';

/** The DropRSVP square mark. Accepts sizing/utility classes via `className`. */
export default function AppLogoIcon({ className }: { className?: string }) {
    return <img src="/logo-mark.png" alt="DropRSVP" className={cn('object-contain dark:invert', className)} />;
}
