import { useEffect, useRef } from 'react';
import { disarm } from '@/components/shadow-html';
import { scopeCss } from '@/lib/scope-css';
import { cn } from '@/lib/utils';

/**
 * Pasted HTML written straight into the page.
 *
 * Its headings are the page's headings, so search engines and SEO tools read
 * them; its CSS is scoped to the box it sits in (lib/scope-css), so it cannot
 * restyle the site around it; and, when the page was last saved by a
 * superadmin, its scripts run here like any other script on the site.
 *
 * Scripts run only for trusted pages because here they act with the
 * viewer's own session — an admin's included. Pages saved by anyone else
 * keep their scripts in the sealed frame (SandboxedHtml) instead.
 */

const FONT_HOSTS = /fonts\.googleapis\.com|fonts\.bunny\.net|use\.typekit\.net|fonts\.cdnfonts\.com|api\.fontshare\.com/i;

export interface InlinePlan {
    css: string;
    body: string;
    bodyClass: string;
    bodyStyle: string;
    scripts: { attrs: [string, string][]; text: string }[];
    fonts: string[];
}

/**
 * Take a pasted document apart for writing into the page. Null when it links
 * a stylesheet that cannot be scoped (one hosted elsewhere, other than a font
 * service), so the caller shows it in isolation instead.
 */
export function planInline(html: string, scope: string, runScripts: boolean): InlinePlan | null {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const fonts: string[] = [];

    for (const link of Array.from(doc.querySelectorAll<HTMLLinkElement>('link[rel~="stylesheet"]'))) {
        const href = link.getAttribute('href') ?? '';

        if (!FONT_HOSTS.test(href)) {
            return null;
        }

        fonts.push(href);
        link.remove();
    }

    let css = Array.from(doc.querySelectorAll('style')).map((style) => {
        style.remove();

        return style.textContent ?? '';
    }).join('\n');

    let unscopable = false;
    css = css.replace(/@import\s+(?:url\(\s*)?["']?([^"')\s;]+)["']?\s*\)?[^;]*;/gi, (_match, url: string) => {
        if (FONT_HOSTS.test(url)) {
            fonts.push(url);
        } else {
            unscopable = true;
        }

        return '';
    });

    if (unscopable) {
        return null;
    }

    const scripts: InlinePlan['scripts'] = [];

    doc.querySelectorAll('script').forEach((script) => {
        // Structured data is served in <head> by the server already.
        if ((script.getAttribute('type') ?? '').toLowerCase() !== 'application/ld+json' && runScripts) {
            scripts.push({ attrs: Array.from(script.attributes).map((a) => [a.name, a.value]), text: script.textContent ?? '' });
        }

        script.remove();
    });

    if (!runScripts) {
        disarm(doc);
    }

    doc.querySelectorAll('title, meta, base').forEach((node) => node.remove());

    return {
        css: scopeCss(css, scope, `${scope} > .drop-body`),
        body: doc.body.innerHTML,
        bodyClass: doc.body.getAttribute('class') ?? '',
        bodyStyle: doc.body.getAttribute('style') ?? '',
        scripts,
        fonts,
    };
}

const attr = (value: string) => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;');

/**
 * The page was loaded long before these scripts ran, so a script waiting for
 * DOMContentLoaded or load would wait forever. While they run, listeners for
 * those two are called straight away instead.
 */
function holdReadyEvents(): () => void {
    const addToDocument = document.addEventListener;
    const addToWindow = window.addEventListener;

    const fire = (target: EventTarget, type: string, listener: EventListenerOrEventListenerObject | null) => {
        window.setTimeout(() => {
            if (typeof listener === 'function') {
                listener.call(target, new Event(type));
            } else {
                listener?.handleEvent(new Event(type));
            }
        }, 0);
    };

    document.addEventListener = function (this: Document, type: string, listener: EventListenerOrEventListenerObject | null, options?: boolean | AddEventListenerOptions) {
        if (type === 'DOMContentLoaded') {
            fire(document, type, listener);

            return;
        }

        if (listener) {
            addToDocument.call(this, type, listener, options);
        }
    } as typeof document.addEventListener;

    window.addEventListener = function (this: Window, type: string, listener: EventListenerOrEventListenerObject | null, options?: boolean | AddEventListenerOptions) {
        if (type === 'load' || type === 'DOMContentLoaded') {
            fire(window, type, listener);

            return;
        }

        if (listener) {
            addToWindow.call(this, type, listener, options);
        }
    } as typeof window.addEventListener;

    return () => {
        document.addEventListener = addToDocument;
        window.addEventListener = addToWindow;
    };
}

/** Run the page's scripts in order; one with a src is waited for before the next. */
async function runScripts(container: Element, scripts: InlinePlan['scripts'], cancelled: () => boolean): Promise<void> {
    const restore = holdReadyEvents();

    try {
        for (const source of scripts) {
            if (cancelled()) {
                break;
            }

            // A script inserted through innerHTML never runs; a fresh element does.
            const script = document.createElement('script');
            source.attrs.forEach(([name, value]) => script.setAttribute(name, value));
            script.textContent = source.text;

            const waits = script.hasAttribute('src') && !script.hasAttribute('async') && script.type !== 'module';
            const loaded = waits ? new Promise<void>((resolve) => {
                script.onload = () => resolve();
                script.onerror = () => resolve();
            }) : null;

            container.appendChild(script);

            if (loaded) {
                await loaded;
            }
        }
    } finally {
        window.setTimeout(restore, 0);
    }
}

export function InlineHtml({ plan, scope, layout, className, contentClass }: {
    plan: InlinePlan;
    /** A class unique to this box, without the dot. */
    scope: string;
    layout: 'content' | 'full';
    className?: string;
    /** The site's article typography, for partial HTML. */
    contentClass: string;
}) {
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const element = box.current;

        if (!element) {
            return;
        }

        plan.fonts.forEach((href) => {
            if (!document.head.querySelector(`link[data-page-font="${CSS.escape(href)}"]`)) {
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                link.dataset.pageFont = href;
                document.head.append(link);
            }
        });

        // A full page gets browser defaults back under its own CSS, as it
        // would have standalone: without this the site's reset (no heading
        // sizes, no list bullets, no margins) applies to everything its CSS
        // does not style itself. In a cascade layer declared after the site's,
        // so it beats the site's reset but loses to the page's own CSS.
        //
        // Not for elements whose look lives in their attributes — SVG's
        // stroke="…"/fill="…", an image's width="…", a table's border="…".
        // Browsers count those as the weakest author styles, so a revert
        // wipes them: icons drew as black blobs or nothing at all.
        const reset = layout === 'full'
            ? `@layer drop-html-defaults { .${scope}, .${scope} *:not(svg, svg *, img, picture, video, canvas, iframe, table, caption, colgroup, col, thead, tbody, tfoot, tr, td, th) { all: revert; } }\n`
            : '';

        element.innerHTML = `<style>${reset}${plan.css}</style>`
            + `<div class="${attr(cn('drop-body', plan.bodyClass))}"${plan.bodyStyle ? ` style="${attr(plan.bodyStyle)}"` : ''}>${plan.body}</div>`;

        let cancelled = false;

        if (plan.scripts.length > 0) {
            runScripts(element.querySelector('.drop-body') ?? element, plan.scripts, () => cancelled);
        }

        return () => {
            cancelled = true;
            element.innerHTML = '';
        };
    }, [plan, scope, layout]);

    return <div ref={box} className={cn(scope, layout === 'content' && contentClass, className)} />;
}
