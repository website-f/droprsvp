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

const REGION = /^[a-z]{2}-[a-z]{2}$/;

/**
 * The region being browsed — the first segment of every public URL (en-my,
 * en-sg…; Admin → Settings → Regions). Set from the `region` page prop: read
 * from the page data in the HTML before the first render, then kept up to
 * date on every Inertia visit (see app.tsx).
 */
let current = LOCALE;

export function setRegion(code?: string | null): void {
    if (code && REGION.test(code)) {
        current = code;
    }
}

/**
 * "en-sg" — for building links: `/${region()}/e/${slug}/`.
 *
 * On a region page the address bar is the truth: Inertia updates the URL
 * before it renders the next page, whereas its `navigate` event (which keeps
 * `current` up to date) fires only after — too late for that first render.
 * Elsewhere (dashboard, account) it is the region last browsed.
 */
export function region(): string {
    if (typeof window !== 'undefined') {
        const first = window.location.pathname.split('/')[1] ?? '';

        if (REGION.test(first)) {
            return first;
        }
    }

    return current;
}

// The first page's props, straight from the HTML, so links in the very first
// render already carry the right region.
if (typeof document !== 'undefined') {
    try {
        const raw = document.querySelector('script[data-page]')?.textContent;
        setRegion(raw ? (JSON.parse(raw) as { props?: { region?: { code?: string } } }).props?.region?.code : null);
    } catch {
        /* no page data (tests, error pages): stay on the default */
    }
}

/** The "browse everything" city slug — /en-my/all/. */
export const ANY = 'all';

/**
 * Build a canonical path in the region being browsed. Empty/nullish segments
 * are dropped, so lp(city, category) works when either is null.
 *
 *   lp()                  -> "/en-my/"
 *   lp('blog', 'my-post') -> "/en-my/blog/my-post/"
 */
export function lp(...segments: Array<string | null | undefined>): string {
    const parts = segments
        .filter((s): s is string => typeof s === 'string')
        .map((s) => s.replace(/^\/+/, '').replace(/\/+$/, ''))
        .filter((s) => s !== '');

    return `/${[current, ...parts].join('/')}/`;
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
