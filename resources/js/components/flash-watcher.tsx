import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

interface FlashProps { flash?: { success?: string; error?: string; warning?: string } }

/**
 * Surfaces server flash messages as toasts on every page, app-wide. Mounted once
 * outside the Inertia page tree, so it reads props via the global router (NOT
 * usePage(), which needs the page context).
 *
 * Laravel flash is one-shot — a controller's ->with('success'/'flash_error'/…)
 * is present only on the immediate redirect target and gone on the next request —
 * so we simply toast whatever's present on each navigation; it never repeats.
 * This means every action that redirects with a message gives the user feedback
 * without each page having to wire it up.
 */
export function FlashWatcher() {
    useEffect(() => {
        const show = (props?: FlashProps) => {
            const flash = props?.flash;

            if (!flash) {
                return;
            }

            if (flash.success) {
                toast.success(flash.success);
            }

            if (flash.error) {
                toast.error(flash.error);
            }

            if (flash.warning) {
                toast.warning(flash.warning);
            }
        };

        // Initial page (e.g. a full-page redirect straight into a flashed page).
        try {
            const el = document.getElementById('app');

            if (el?.dataset.page) {
                show(JSON.parse(el.dataset.page).props as FlashProps);
            }
        } catch {
            /* ignore malformed initial payload */
        }

        // Subsequent Inertia navigations (the common case: redirect after a POST).
        return router.on('navigate', (event) => show(event.detail.page.props as FlashProps));
    }, []);

    return null;
}
