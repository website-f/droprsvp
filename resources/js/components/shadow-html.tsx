import { useEffect, useRef } from 'react';

/**
 * A pasted HTML document shown inside the site's own page ("HTML mode").
 *
 * It goes into a shadow root, which does two jobs at once: the document's
 * CSS cannot reach the site header and footer around it, and the site's CSS
 * (Tailwind's resets especially) cannot flatten its design. Three things a
 * shadow root does not do on its own are handled here:
 *
 *   - `html`, `body` and `:root` selectors match nothing inside it, so they
 *     are pointed at the host and at a wrapper standing in for <body>;
 *   - fonts declared inside it are ignored by browsers, so font stylesheets
 *     and @font-face rules are lifted into the main document's <head>;
 *   - links to #anchors look for the id in the main document, so they are
 *     scrolled to here instead.
 *
 * Anything that could run is removed before it is inserted (the server has
 * already done the same: App\Support\PageHtml).
 */

const DROP = 'script, noscript, base, meta, object, embed, applet, frame, frameset';
const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'xlink:href', 'poster', 'data'];
const FONT_HOSTS = /fonts\.googleapis\.com|fonts\.bunny\.net|use\.typekit\.net|fonts\.cdnfonts\.com|api\.fontshare\.com/i;

/** Remove whatever could run from a parsed document, in place. */
export function disarm(doc: Document): void {
    doc.querySelectorAll(DROP).forEach((node) => node.remove());

    doc.querySelectorAll('*').forEach((element) => {
        for (const attribute of Array.from(element.attributes)) {
            const name = attribute.name.toLowerCase();

            if (name.startsWith('on') || name === 'srcdoc') {
                element.removeAttribute(attribute.name);
            } else if (URL_ATTRIBUTES.includes(name) && /^\s*(javascript|vbscript|data:text\/html)/i.test(attribute.value)) {
                element.setAttribute(attribute.name, '#');
            }
        }
    });
}

/** Point root selectors at the shadow host and the stand-in for <body>. */
function scope(css: string): string {
    return css
        .replace(/:root\b/g, ':host')
        .replace(/(^|[\s,{}>+~(])(html|body)(?=[\s,{.#:[>+~)]|$)/gim, (_match, before: string, tag: string) =>
            `${before}${tag.toLowerCase() === 'html' ? ':host' : '.drop-body'}`);
}

/** Fonts only load when declared in the main document, so lift them there. */
function hoistFonts(doc: Document, css: string[], target: Document): void {
    const hrefs = new Set<string>();

    doc.querySelectorAll<HTMLLinkElement>('link[rel~="stylesheet"][href]').forEach((link) => {
        if (FONT_HOSTS.test(link.href)) {
            hrefs.add(link.getAttribute('href')!);
        }
    });

    const faces: string[] = [];

    for (const text of css) {
        for (const match of text.matchAll(/@import\s+(?:url\(\s*)?["']?([^"')\s;]+)["']?\s*\)?[^;]*;/gi)) {
            if (FONT_HOSTS.test(match[1])) {
                hrefs.add(match[1]);
            }
        }

        faces.push(...(text.match(/@font-face\s*{[^}]*}/gi) ?? []));
    }

    hrefs.forEach((href) => {
        if (!target.head.querySelector(`link[data-page-font="${CSS.escape(href)}"]`)) {
            const link = target.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.dataset.pageFont = href;
            target.head.append(link);
        }
    });

    if (faces.length > 0) {
        let style = target.getElementById('page-html-fonts');

        if (!style) {
            style = target.createElement('style');
            style.id = 'page-html-fonts';
            target.head.append(style);
        }

        const known = style.textContent ?? '';
        style.textContent = known + faces.filter((face) => !known.includes(face)).join('\n');
    }
}

/**
 * The nodes that go into the shadow root, made in `target` — the document the
 * root lives in. That is not always this one: in the page builder the canvas
 * is an iframe, and fonts lifted into the editor's <head> never reach it.
 */
function build(html: string, target: Document): Node[] {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    disarm(doc);

    const styles = Array.from(doc.querySelectorAll('style'));
    styles.forEach((style) => {
        style.textContent = scope(style.textContent ?? '');
    });
    hoistFonts(doc, styles.map((style) => style.textContent ?? ''), target);

    const base = target.createElement('style');
    base.textContent = ':host { display: block; } .drop-body { display: block; min-height: 1px; }';

    // The <head>'s stylesheets, in their own order, ahead of the content.
    const head = Array.from(doc.head.querySelectorAll('style, link[rel~="stylesheet"]'))
        .map((node) => target.importNode(node, true));

    // <body> itself, with its class and inline style, so rules aimed at the
    // body still have something to land on.
    const wrapper = target.createElement('div');
    wrapper.className = ['drop-body', doc.body.getAttribute('class') ?? ''].join(' ').trim();

    for (const attribute of Array.from(doc.body.attributes)) {
        if (attribute.name !== 'class') {
            wrapper.setAttribute(attribute.name, attribute.value);
        }
    }

    wrapper.append(...Array.from(doc.body.childNodes).map((node) => target.importNode(node, true)));

    return [base, ...head, wrapper];
}

export function ShadowHtml({ html, className }: { html: string; className?: string }) {
    const host = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const element = host.current;

        if (!element) {
            return;
        }

        const root = element.shadowRoot ?? element.attachShadow({ mode: 'open' });
        root.replaceChildren(...build(html, element.ownerDocument));

        // "#pricing" means the section in this page, not an id in the main document.
        const onClick = (event: Event) => {
            const anchor = (event.target as Element | null)?.closest?.('a[href^="#"]');
            const id = anchor?.getAttribute('href')?.slice(1);
            const target = id ? root.getElementById(decodeURIComponent(id)) : null;

            if (target) {
                event.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };

        root.addEventListener('click', onClick);

        return () => root.removeEventListener('click', onClick);
    }, [html]);

    return <div ref={host} className={className} />;
}
