import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

/**
 * Compact light/dark switch for headers. Flips between light and dark based on
 * what's currently showing (so it also works when the user is on "system").
 * The full light/dark/system control lives on Settings → Appearance.
 */
export function AppearanceToggle({ className }: { className?: string }) {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isDark = resolvedAppearance === 'dark';
    const label = isDark ? 'Switch to light mode' : 'Switch to dark mode';

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label={label}
            title={label}
            onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
            className={cn(className)}
        >
            {isDark ? <Sun className="size-5" /> : <Moon className="size-5" />}
            <span className="sr-only">{label}</span>
        </Button>
    );
}
