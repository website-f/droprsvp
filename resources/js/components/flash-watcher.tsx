import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

interface PageProps { flash?: { warning?: string } }

/**
 * Surfaces the shared `flash.warning` prop as a toast on every page. Mounted once
 * app-wide (outside the Inertia page tree), so it reads props via the global
 * router — NOT usePage(), which requires the page context. Used for session-timeout
 * notices: when a protected route bounces a guest to /login, the server flashes a
 * warning and this shows it, so the user knows why they landed on the login screen.
 * (success/error stay inline per page, so only `warning` is toasted here.)
 */
export function FlashWatcher() {
    const last = useRef<string | undefined>(undefined);

    useEffect(() => {
        const show = (props?: PageProps) => {
            const warning = props?.flash?.warning;

            if (warning && warning !== last.current) {
                last.current = warning;
                toast.warning(warning);
            }
        };

        // Initial page (e.g. a session-timeout full reload straight to /login).
        try {
            const el = document.getElementById('app');

            if (el?.dataset.page) {
                show(JSON.parse(el.dataset.page).props as PageProps);
            }
        } catch {
            /* ignore malformed initial payload */
        }

        // Subsequent Inertia navigations (the common case: clicking within the app
        // when the session has expired → server 302s to /login with the warning).
        return router.on('navigate', (event) => {
            show((event.detail.page.props as PageProps));
        });
    }, []);

    return null;
}
