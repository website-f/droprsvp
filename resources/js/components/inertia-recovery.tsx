import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Turns "the server sent a page instead of Inertia data" into a normal page load.
 *
 * When an in-app visit gets back something without the X-Inertia header,
 * Inertia shows the raw response in a dialog. For an error page that is at least
 * informative. For an ordinary 200 page it is just a blank white box — the page's
 * own scripts cannot run inside the dialog's frame — and that is exactly what
 * organizers on Safari were seeing when they opened Events from the sidebar.
 *
 * The cause there: production's .htaccess used to 301 every app URL to a
 * trailing slash (/host/events -> /host/events/) with no cache headers. Safari
 * keeps that redirect forever, reuses it for the app's in-app requests, and drops
 * X-Inertia on the redirected hop — so the server, correctly, answered with the
 * full HTML page. The redirect is now limited to public pages, but a browser
 * that already cached it keeps it, so this has to recover on the client too.
 *
 * Only for GET visits answered with a success status: a page that came back
 * where data was expected is never an outcome worth showing as a dialog, and
 * loading the same URL as a full page gives the person exactly what they asked
 * for. Real errors (4xx/5xx) and form submissions keep Inertia's default
 * behaviour, so nothing is silently swallowed or re-submitted.
 *
 * Mounted once at the app root, outside the page tree — router events only, no
 * usePage(), same as FlashWatcher.
 */
export function InertiaRecovery() {
    useEffect(() => {
        let visit: { url: URL; method: string } | null = null;

        const offStart = router.on('start', (event) => {
            visit = { url: event.detail.visit.url, method: String(event.detail.visit.method).toLowerCase() };
        });

        const offException = router.on('httpException', (event) => {
            const status = event.detail.response.status;

            if (!visit || visit.method !== 'get' || status >= 400) {
                return; // a genuine error, or a submission: leave it to Inertia
            }

            // Same origin only — never hand the browser somewhere unexpected.
            if (visit.url.origin !== window.location.origin) {
                return;
            }

            event.preventDefault();
            window.location.assign(visit.url.href);

            return false;
        });

        return () => {
            offStart();
            offException();
        };
    }, []);

    return null;
}
