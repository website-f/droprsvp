import { Monitor, Smartphone, Tablet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { cleanFragment, partialClass } from '@/components/html-content';
import type { HtmlLayout } from '@/components/html-content';
import { hasScripts, sandboxDocument } from '@/components/sandboxed-html';
import { disarm } from '@/components/shadow-html';
import { cn } from '@/lib/utils';

/**
 * What the preview shows: the document with its scripts and handlers already
 * removed, as it will be once saved. The sandbox would block them anyway, but
 * then the console fills with "blocked script" errors and the preview hints
 * at behaviour the published page will not have.
 */
function disarmed(html: string): string {
    if (html.trim() === '') {
        return '';
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    disarm(doc);

    return `<!DOCTYPE html>${doc.documentElement.outerHTML}`;
}

/**
 * Partial HTML as the public page will show it: inside the site's own
 * stylesheet, typography and readable column. The frame borrows the
 * stylesheets this page already loaded, so it is the real site CSS rather
 * than an imitation of it.
 */
function asSiteContent(html: string): string {
    if (html.trim() === '') {
        return '';
    }

    const sheets = Array.from(document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]'))
        .map((link) => `<link rel="stylesheet" href="${link.href}">`)
        .join('');
    // Vite's dev server injects CSS as <style> tags rather than links.
    const inline = Array.from(document.head.querySelectorAll('style'))
        .map((style) => style.outerHTML)
        .join('');
    const theme = document.documentElement.classList.contains('dark') ? 'dark' : '';

    return `<!DOCTYPE html><html class="${theme}"><head><meta charset="utf-8">${sheets}${inline}</head>`
        + '<body class="bg-background font-sans text-foreground antialiased">'
        + `<main class="mx-auto w-full max-w-3xl px-6 py-12"><article class="${partialClass}">${cleanFragment(html)}</article></main>`
        + '</body></html>';
}

export type PreviewDevice = 'desktop' | 'tablet' | 'phone';

const WIDTHS: Record<PreviewDevice, number | '100%'> = { desktop: '100%', tablet: 768, phone: 390 };
const ICONS = { desktop: Monitor, tablet: Tablet, phone: Smartphone };

/**
 * A live preview of pasted HTML, at desktop, tablet and phone width.
 *
 * A frame rather than the shadow root the page itself uses, because a frame
 * has its own viewport: the document's media queries respond to the width
 * chosen here, which is the whole point of a phone preview. Scripts never run
 * in it (no allow-scripts, and they are stripped before it is built), so
 * whatever is pasted can only be looked at. It does share this page's origin:
 * a frame with an origin of its own is refused the site's self-hosted fonts,
 * and partial HTML is meant to preview in exactly those. Without scripts,
 * nothing inside can use that access. Typing is debounced so the frame isn't
 * rebuilt on every keystroke.
 */
export function HtmlPreview({
    html,
    height = 640,
    empty = 'Paste your HTML to see it here.',
    devices = ['desktop', 'tablet', 'phone'],
    layout = 'full',
    scripts = true,
    className,
}: {
    html: string;
    /** "content" shows partial HTML in the site's styling, as the page will. */
    layout?: HtmlLayout;
    /** Run the HTML's scripts (pages). Off for emails, which no inbox would run. */
    scripts?: boolean;
    height?: number;
    empty?: string;
    devices?: PreviewDevice[];
    className?: string;
}) {
    const [device, setDevice] = useState<PreviewDevice>(devices[0]);
    // HTML with scripts previews the way the page runs it: scripts on, in a
    // sandbox of its own (see SandboxedHtml). Everything else as before.
    const scripted = scripts && hasScripts(html);
    const render = (source: string) => {
        if (scripts && hasScripts(source)) {
            return sandboxDocument(source, layout, { viewportHeight: height, autoHeight: false, linkTarget: '_blank', contentClass: partialClass, container: true });
        }

        return layout === 'content' ? asSiteContent(source) : disarmed(source);
    };
    const [shown, setShown] = useState(() => render(html));

    useEffect(() => {
        const timer = window.setTimeout(() => setShown(render(html)), 400);

        return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- render reads only html, layout and height
    }, [html, layout, height]);

    return (
        <div className={cn('overflow-hidden rounded-2xl border border-border bg-card shadow-sm', className)}>
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-3 py-2">
                <span className="text-sm font-medium">Preview</span>
                <div className="flex gap-1" role="group" aria-label="Preview width">
                    {devices.map((d) => {
                        const Icon = ICONS[d];

                        return (
                            <button
                                key={d}
                                type="button"
                                onClick={() => setDevice(d)}
                                aria-pressed={device === d}
                                title={`${d[0].toUpperCase()}${d.slice(1)}`}
                                className={cn('flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground', device === d && 'bg-accent text-foreground')}
                            >
                                <Icon className="size-4" />
                            </button>
                        );
                    })}
                </div>
            </div>
            <div className="flex overflow-x-auto bg-muted/40 p-3">
                {shown.trim() === '' ? (
                    <div className="flex w-full items-center justify-center text-sm text-muted-foreground" style={{ height }}>{empty}</div>
                ) : (
                    <iframe
                        title="Preview"
                        // Scripts and same-origin are never granted together: that
                        // pair would undo the sandbox. A scripted preview gets
                        // scripts and an origin of its own; any other gets the
                        // site's origin (for its fonts) and no scripts.
                        sandbox={scripted ? 'allow-scripts allow-forms allow-popups allow-modals' : 'allow-same-origin'}
                        // A fresh frame when that switches, as a sandbox is fixed at load.
                        key={scripted ? 'scripted' : 'static'}
                        srcDoc={shown}
                        className="mx-auto shrink-0 rounded-lg border border-border bg-white"
                        // At its true width even when that overflows (it scrolls),
                        // or the media queries would fire at the wrong size.
                        style={{ width: WIDTHS[device], height }}
                    />
                )}
            </div>
        </div>
    );
}
