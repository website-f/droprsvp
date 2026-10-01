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
 * The payloads are built on the server (App\Support\Tracking) from the
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

/** Has gtag.js itself loaded? The inline `gtag` stub exists even when an ad blocker stopped it. */
function gaLoaded(): boolean {
    return !!(window as unknown as { google_tag_manager?: unknown }).google_tag_manager;
}

/** Wait up to `ms` for gtag.js to load. */
async function waitForGa(ms: number): Promise<boolean> {
    for (let waited = 0; waited < ms; waited += 200) {
        if (gaLoaded()) {
            return true;
        }

        await new Promise((resolve) => window.setTimeout(resolve, 200));
    }

    return gaLoaded();
}

/**
 * Report a settled order to GA — in step with the server, so it is counted once.
 *
 * The server is the safety net (App\Services\GoogleAnalytics): every few
 * minutes it reports any sale GA has not had. So this page must only send
 * when it can actually deliver, and must tell the server when it has:
 *
 *   1. wait for gtag.js to load — if an ad blocker or tracking protection
 *      stopped it, do nothing and leave the sale to the server;
 *   2. CLAIM the sale — the server grants one claim only, so a refresh, a
 *      second tab, or a sync running this second cannot also send it;
 *   3. send `purchase`, by beacon so it survives the tab being closed;
 *   4. ACK when GA has dispatched it — also by beacon, for the same reason.
 *
 * A claim with no ack (closed mid-send) lapses after ten minutes and the server
 * reports it instead, with the same transaction id GA de-duplicates on.
 */
export function PurchaseEvent({ payload, reference }: { payload: Record<string, unknown> | null; reference: string }) {
    useEffect(() => {
        if (!payload || !reference) {
            return;
        }

        let cancelled = false;
        const base = `/orders/${encodeURIComponent(reference)}/analytics`;

        (async () => {
            if (!(await waitForGa(8000)) || cancelled) {
                return; // blocked or not loaded: the server will report it
            }

            const send = gtag();

            if (!send) {
                return;
            }

            const claim = await fetch(`${base}/claim`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            }).then((r) => (r.ok ? r.json() : null)).catch(() => null);

            if (cancelled || !claim?.send) {
                return; // already reported, or another tab / the server has it
            }

            let acked = false;
            const ack = () => {
                if (acked) {
                    return;
                }

                acked = true;

                if (!navigator.sendBeacon?.(`${base}/ack`)) {
                    void fetch(`${base}/ack`, { method: 'POST', credentials: 'same-origin', keepalive: true }).catch(() => {});
                }
            };

            send('event', 'purchase', { ...payload, transport_type: 'beacon', event_callback: ack });
        })();

        return () => {
            cancelled = true;
        };
    }, [payload, reference]);

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
