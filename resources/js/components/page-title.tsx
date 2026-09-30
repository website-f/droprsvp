import { Head, usePage } from '@inertiajs/react';

/**
 * The page title, taken from the server rather than re-guessed on the client.
 *
 * Inertia is a SPA, so after the first load the browser title is whatever the
 * page's <Head> says — and the public pages were passing a bare record name.
 * A visitor who landed on the home page and clicked through to an event ended
 * up with "Some Event · DropRSVP" in the tab while the server had rendered
 * "Some Event – Kajang, 10 Oct 2026 | DropRSVP" into the HTML. Two different
 * titles for one page, and the one Google Analytics reports (and any SEO
 * extension reads out of the live DOM) was the wrong one.
 *
 * `pageTitle` is shared by HandleInertiaRequests straight from SeoManager, so
 * there is exactly one place that decides what a page is called.
 *
 * `fallback` covers panel screens that never touch SeoManager, where the
 * shared value is just the site name.
 */
export function PageTitle({ fallback }: { fallback?: string }) {
    const site = usePage().props.name as string | undefined;
    const shared = usePage().props.pageTitle as string | undefined;

    // Nothing page-specific was set server-side — use whatever the screen
    // itself knows, so the panel keeps its "Dashboard", "Attendees" and so on.
    const title = !shared || (fallback && shared === site) ? fallback : shared;

    return <Head title={title ?? shared ?? ''} />;
}
