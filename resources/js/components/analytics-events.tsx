import { useEffect } from 'react';

/**
 * GA4 ecommerce events.
 *
 * The property was reporting RM0 revenue and zero purchasers while tickets were
 * genuinely selling. Nothing was wrong with the sales — nothing was ever
 * SENT. The site only emitted page views, and GA4's "Drive sales" reports are
 * built entirely from ecommerce events, so every one of them had no data to
 * draw from.
 *
 * The payloads are built on the server (App\Support\GoogleAnalytics) from the
 * order that was actually settled, so what GA reports is what the database
 * holds rather than something reassembled from the DOM.
 *
 * Nothing here assumes gtag exists: an ad blocker, or a page outside the public
 * pages where the tag renders, simply means the event is dropped.
 */

type Gtag = (command: string, event: string, params?: Record<string, unknown>) => void;

function gtag(): Gtag | null {
    const fn = (window as unknown as { gtag?: Gtag }).gtag;

    return typeof fn === 'function' ? fn : null;
}

/**
 * Has this exact event already been sent from this browser?
 *
 * The confirmation page is refreshed, bookmarked and reopened from the emailed
 * link, and GA would count each visit as another sale. GA de-duplicates on
 * `transaction_id` server-side, but only within a window, so the honest fix is
 * not to send it twice. sessionStorage can throw in a private window, hence the
 * try/catch: a failure there must never stop the page rendering, and sending
 * the event twice is the safe side of that trade.
 */
function sendOnce(key: string): boolean {
    try {
        if (window.sessionStorage.getItem(key)) {
            return false;
        }

        window.sessionStorage.setItem(key, '1');
    } catch {
        // No storage — carry on and send. Better a possible duplicate than a
        // sale that is never recorded at all.
    }

    return true;
}

/** Fire `purchase` once for a settled order. */
export function PurchaseEvent({ payload }: { payload: Record<string, unknown> | null }) {
    useEffect(() => {
        if (!payload) {
            return;
        }

        const id = String(payload.transaction_id ?? '');
        const send = gtag();

        if (!send || !id || !sendOnce(`ga:purchase:${id}`)) {
            return;
        }

        send('event', 'purchase', payload);
    }, [payload]);

    return null;
}

/**
 * Fire `begin_checkout` when the buyer reaches the checkout page.
 *
 * Without it the funnel in GA starts at "purchase", so the checkout-journey
 * report can show a completion but never an abandonment — which is the half of
 * that report worth reading.
 */
export function BeginCheckoutEvent({ payload }: { payload: Record<string, unknown> | null }) {
    useEffect(() => {
        if (!payload) {
            return;
        }

        const id = String(payload.transaction_id ?? '');
        const send = gtag();

        if (!send || !sendOnce(`ga:begin_checkout:${id}`)) {
            return;
        }

        send('event', 'begin_checkout', payload);
    }, [payload]);

    return null;
}
