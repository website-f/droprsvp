import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Keeps the analytics tags off the back office during in-app navigation.
 *
 * The server refusing to render the tag on /admin and /host was only half the
 * job, and the reason "dashboard - DropRSVP" kept turning up in the property
 * afterwards. Inertia is a single-page app: a visitor who arrives on a public
 * page has the tag loaded, and every click from then on is a history change
 * rather than a new document. GA4's enhanced measurement reports each of those
 * as its own page_view, tag still live, wherever they went — including straight
 * into the panel. Clarity does the same, and worse: it is recording the screen.
 *
 * So the browser has to be told to stop. Both vendors support exactly that:
 *
 *   * GA reads `window['ga-disable-<MEASUREMENT_ID>']` before sending anything,
 *     which is its documented opt-out switch;
 *   * Clarity takes `clarity('stop')` and `clarity('start')`.
 *
 * The switch is thrown on Inertia's `before` event — before the visit is made,
 * and so before history changes — because flipping it afterwards would be a
 * race with GA's own history listener that we would sometimes lose.
 *
 * The path list comes from the server (App\Support\Tracking::privatePathPattern),
 * so there is one definition of "back office" rather than two that drift.
 */

interface TrackingConfig {
    ga: string | null;
    clarity: string | null;
    /** Source of a RegExp matching every private path. */
    private: string;
    /** Were the tags rendered with this document? False on a buyer's private page. */
    active?: boolean;
}

type Gtag = (...args: unknown[]) => void;

/**
 * Load the tags into a session that began on a private page.
 *
 * A buyer who lands on /login or their dashboard gets no tags (those pages are
 * not measured), and being a SPA, no new document arrives as they go on to an
 * event and its checkout. So the session was invisible to GA from start to
 * finish. This loads gtag (and Clarity) the first time it reaches a public
 * page — exactly the snippet the server would have rendered there.
 */
function loadTags(cfg: TrackingConfig): void {
    const w = window as unknown as { dataLayer?: unknown[]; gtag?: Gtag; clarity?: unknown };

    if (cfg.ga && typeof w.gtag !== 'function') {
        w.dataLayer = w.dataLayer || [];
        // gtag.js reads the `arguments` object itself, not an array copy.
        w.gtag = function gtag() {
            // eslint-disable-next-line prefer-rest-params
            w.dataLayer!.push(arguments);
        };
        w.gtag('js', new Date());
        w.gtag('config', cfg.ga);

        const s = document.createElement('script');
        s.async = true;
        s.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(cfg.ga)}`;
        document.head.appendChild(s);
    }

    if (cfg.clarity && typeof w.clarity !== 'function') {
        const c = window as unknown as { clarity: ((...a: unknown[]) => void) & { q?: unknown[] } };
        c.clarity = c.clarity || function clarity() {
            // eslint-disable-next-line prefer-rest-params
            (c.clarity.q = c.clarity.q || []).push(arguments);
        };

        const s = document.createElement('script');
        s.async = true;
        s.src = `https://www.clarity.ms/tag/${encodeURIComponent(cfg.clarity)}`;
        document.head.appendChild(s);
    }
}

type Clarity = (command: string) => void;

function config(): TrackingConfig | null {
    return (window as unknown as { __tracking?: TrackingConfig }).__tracking ?? null;
}

/** Turn both tags off (private) or back on (public). */
function setEnabled(cfg: TrackingConfig, enabled: boolean): void {
    if (cfg.ga) {
        // GA's own opt-out flag. Read on every send, so this takes effect
        // immediately and retroactively for anything not yet dispatched.
        (window as unknown as Record<string, boolean>)[`ga-disable-${cfg.ga}`] = !enabled;
    }

    const clarity = (window as unknown as { clarity?: Clarity }).clarity;

    if (cfg.clarity && typeof clarity === 'function') {
        try {
            clarity(enabled ? 'start' : 'stop');
        } catch {
            // An old or blocked Clarity build may not know these commands.
            // Recording a page we would rather it didn't is not worth an
            // exception that breaks navigation.
        }
    }
}

export function TrackingGuard() {
    useEffect(() => {
        const cfg = config();

        if (!cfg) {
            return; // tracking is off, or this page never loaded the tags
        }

        const isPrivate = (url: string): boolean => {
            try {
                return new RegExp(cfg.private).test(new URL(url, window.location.origin).pathname);
            } catch {
                return false;
            }
        };

        // The page we started on. Set explicitly so the flag is never left
        // over from a previous state.
        setEnabled(cfg, !isPrivate(window.location.pathname));

        // Started on a private page, so the tags were never rendered: load them
        // on the first arrival at a public one (never before — nothing is sent
        // from the private pages themselves).
        let loaded = cfg.active !== false;
        const ensureLoaded = () => {
            if (!loaded && !isPrivate(window.location.pathname)) {
                loaded = true;
                loadTags(cfg);
            }
        };

        // Before the visit, so the switch is already thrown by the time the
        // history entry changes and GA's listener runs.
        const before = router.on('before', (event) => {
            setEnabled(cfg, !isPrivate(String(event.detail.visit.url)));
        });

        // And again on arrival, which catches a redirect landing somewhere
        // other than where the visit was aimed.
        const navigate = router.on('navigate', () => {
            ensureLoaded();
            setEnabled(cfg, !isPrivate(window.location.pathname));
        });

        return () => {
            before();
            navigate();
        };
    }, []);

    return null;
}
