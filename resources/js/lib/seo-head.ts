import { router } from '@inertiajs/react';

/**
 * Keeps the SEO block in <head> in step with the page on screen.
 *
 * The server writes the meta, Open Graph and JSON-LD tags into <head> on a
 * full page load, between two comments (SeoManager::render). Moving around
 * the app doesn't reload the page, so without this the <head> kept describing
 * whichever page was loaded first: an event reached from the homepage showed
 * the homepage's og:type and a JSON-LD graph with no Event in it.
 *
 * Every in-app visit now brings its own tags (the seoHead prop) and they are
 * swapped in here. Pages loaded in full are remembered as they were served,
 * so going Back to one restores its tags rather than keeping the last page's.
 */
const START = 'seo-head';
const END = '/seo-head';

function markers(): [Comment, Comment] {
    let start: Comment | null = null;
    let end: Comment | null = null;

    document.head.childNodes.forEach((node) => {
        if (node.nodeType === Node.COMMENT_NODE) {
            const text = (node as Comment).data.trim();

            if (text === START) {
                start = node as Comment;
            } else if (text === END) {
                end = node as Comment;
            }
        }
    });

    if (start && end) {
        return [start, end];
    }

    // A page served without the block (an error page, say): make one.
    const made: [Comment, Comment] = [document.createComment(START), document.createComment(END)];
    document.head.append(...made);

    return made;
}

/** The tags currently between the markers, as HTML. */
function current(): string {
    const [start, end] = markers();
    let html = '';

    for (let node = start.nextSibling; node && node !== end; node = node.nextSibling) {
        if (node.nodeType === Node.ELEMENT_NODE) {
            html += (node as Element).outerHTML;
        }
    }

    return html;
}

function replace(html: string): void {
    const [start, end] = markers();

    while (start.nextSibling && start.nextSibling !== end) {
        start.nextSibling.remove();
    }

    // Our own server's markup. The only script in it is JSON-LD, which a
    // browser never runs.
    end.before(document.createRange().createContextualFragment(html));
}

export function installSeoHeadSync(): void {
    const served = new Map<string, string>();

    router.on('navigate', (event) => {
        const { url, props } = event.detail.page;
        const html = (props as Record<string, unknown>).seoHead;

        if (typeof html === 'string') {
            served.set(url, html);
            replace(html);

            return;
        }

        // No tags with the visit: either the first, full load — whose tags the
        // server already wrote, so remember them — or a partial reload or a
        // step back through history to a page seen before.
        if (!served.has(url)) {
            served.set(url, current());

            return;
        }

        replace(served.get(url)!);
    });
}
