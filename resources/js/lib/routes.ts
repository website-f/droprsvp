/**
 * Canonical public URLs for the React side — the mirror of App\Support\Url.
 *
 * Every indexable page is served at a trailing-slash URL
 * (https://www.droprsvp.com/en-my/blog/), and Apache 301s the slashless form to
 * it (see the "Canonical path" rule in public/.htaccess). So a link written
 * without the slash costs the visitor an extra round trip AND hands Google a
 * second URL for the same page — which is what splits one page's ranking in two
 * and shows up in Search Console as a duplicate.
 *
 * Build every internal link through here so the href, the <link rel=canonical>
 * and the sitemap all agree on exactly one string. There's a test that fails the
 * build if a slashless /en-my link creeps back in — see
 * tests/Feature/TrailingSlashTest.php.
 */
export const LOCALE = 'en-my';

/** The "browse everything" city slug — /en-my/all/. */
export const ANY = 'all';

/**
 * Build a canonical locale path. Empty/nullish segments are dropped, so
 * lp(city, category) works when either is null.
 *
 *   lp()                  -> "/en-my/"
 *   lp('blog', 'my-post') -> "/en-my/blog/my-post/"
 */
export function lp(...segments: Array<string | null | undefined>): string {
    const parts = segments
        .filter((s): s is string => typeof s === 'string')
        .map((s) => s.replace(/^\/+/, '').replace(/\/+$/, ''))
        .filter((s) => s !== '');

    return `/${[LOCALE, ...parts].join('/')}/`;
}

/**
 * Add the canonical trailing slash to an already-built URL, leaving any query
 * string and fragment where they are. Anything whose last segment looks like a
 * file (calendar.ics, sitemap.xml, an uploaded image) is returned untouched.
 */
export function slash(url: string): string {
    const [beforeHash, hash] = splitOnce(url, '#');
    const [path, query] = splitOnce(beforeHash, '?');
    const last = path.slice(path.lastIndexOf('/') + 1);
    const withSlash = path === '' || path.endsWith('/') || last.includes('.') ? path : `${path}/`;

    return withSlash + (query === null ? '' : `?${query}`) + (hash === null ? '' : `#${hash}`);
}

function splitOnce(value: string, separator: string): [string, string | null] {
    const at = value.indexOf(separator);

    return at === -1 ? [value, null] : [value.slice(0, at), value.slice(at + separator.length)];
}
