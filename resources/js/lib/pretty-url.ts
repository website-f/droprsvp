/** Query keys that are tracking, not address — safe to hide from a label. */
const TRACKING = /^(utm_|stkn$|fbclid$|gclid$|igshid$|igsh$|mc_cid$|mc_eid$|ref$|ref_src$|si$)/i;

/**
 * A website URL as something a person can read.
 *
 * Organizers paste whatever the share button gave them, which for Instagram is
 * the profile URL plus a hundred characters of campaign tracking:
 *
 *   https://www.instagram.com/3dexpress?utm_source=ig_web_button_share_sheet&stkn=ZDNlZDc0MzIxNw--
 *
 * Printed raw that overflows its column and tells the reader nothing. This
 * keeps the part that identifies the site and drops the rest. The LINK still
 * points at the full original URL — the tracking may be load-bearing for
 * whoever generated it, so it is hidden, never removed.
 */
export function prettyUrl(raw: string | null | undefined, maxLength = 48): string {
    if (!raw) {
        return '';
    }

    const trimmed = raw.trim();

    let url: URL;

    try {
        // A bare "instagram.com/x" is not parseable without a scheme, and is
        // exactly what someone types.
        url = new URL(/^https?:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`);
    } catch {
        // Not a URL at all. Show it as typed rather than an empty cell.
        return trimmed.length > maxLength ? `${trimmed.slice(0, maxLength - 1)}…` : trimmed;
    }

    const host = url.hostname.replace(/^www\./i, '');

    // Keep any query that is genuinely part of the address (?page=2), drop the
    // campaign noise.
    const kept = [...url.searchParams.entries()].filter(([key]) => !TRACKING.test(key));
    const query = kept.length ? `?${kept.map(([k, v]) => (v ? `${k}=${v}` : k)).join('&')}` : '';

    const path = url.pathname === '/' ? '' : url.pathname.replace(/\/$/, '');
    const label = `${host}${path}${query}`;

    return label.length > maxLength ? `${label.slice(0, maxLength - 1)}…` : label;
}

/**
 * The same URL made safe to put in an href.
 *
 * Adds the scheme a person leaves off, and refuses anything that is not http
 * or https — a `javascript:` value in a profile field is a stored-XSS vector
 * the moment it reaches an anchor.
 */
export function safeHref(raw: string | null | undefined): string | null {
    if (!raw) {
        return null;
    }

    const trimmed = raw.trim();

    try {
        const url = new URL(/^[a-z][a-z0-9+.-]*:/i.test(trimmed) ? trimmed : `https://${trimmed}`);

        return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
    } catch {
        return null;
    }
}
