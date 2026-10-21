import { Monitor, Smartphone, Tablet } from 'lucide-react';
import { useEffect, useState } from 'react';
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

export type PreviewDevice = 'desktop' | 'tablet' | 'phone';

const WIDTHS: Record<PreviewDevice, number | '100%'> = { desktop: '100%', tablet: 768, phone: 390 };
const ICONS = { desktop: Monitor, tablet: Tablet, phone: Smartphone };

/**
 * A live preview of pasted HTML, at desktop, tablet and phone width.
 *
 * A frame rather than the shadow root the page itself uses, because a frame
 * has its own viewport: the document's media queries respond to the width
 * chosen here, which is the whole point of a phone preview. sandbox="" — no
 * scripts, no access to this page — so whatever is pasted can only be looked
 * at. Typing is debounced so the frame isn't rebuilt on every keystroke.
 */
export function HtmlPreview({
    html,
    height = 640,
    empty = 'Paste your HTML to see it here.',
    devices = ['desktop', 'tablet', 'phone'],
    className,
}: {
    html: string;
    height?: number;
    empty?: string;
    devices?: PreviewDevice[];
    className?: string;
}) {
    const [device, setDevice] = useState<PreviewDevice>(devices[0]);
    const [shown, setShown] = useState(() => disarmed(html));

    useEffect(() => {
        const timer = window.setTimeout(() => setShown(disarmed(html)), 400);

        return () => window.clearTimeout(timer);
    }, [html]);

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
                        sandbox=""
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
