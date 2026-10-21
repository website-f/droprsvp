import { GalleryHorizontalEnd } from 'lucide-react';
import { useRef, useState } from 'react';
import type { RefObject } from 'react';
import { MediaPicker } from '@/components/media/media-picker';
import type { MediaItem } from '@/lib/upload';

const attr = (value: string) => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

/**
 * The <img> tag for a picked image. An email gets the attributes inboxes
 * need — a width no wider than the 600px column, display:block, no border —
 * where a web page only needs to stay inside its container.
 */
export function imageTag(item: MediaItem, kind: 'page' | 'email'): string {
    const alt = attr(item.alt ?? '');

    if (kind === 'email') {
        const width = item.width ? Math.min(item.width, 600) : 600;

        return `<img src="${attr(item.url)}" alt="${alt}" width="${width}" style="display:block;max-width:100%;height:auto;border:0;">`;
    }

    return `<img src="${attr(item.url)}" alt="${alt}"${item.width ? ` width="${item.width}"` : ''}${item.height ? ` height="${item.height}"` : ''} style="max-width:100%;height:auto;">`;
}

/**
 * "Insert image" for the HTML editors: pick from the Media Library (or upload)
 * and the <img> tag goes in where the cursor was.
 */
export function InsertImageButton({ textarea, value, onChange, kind }: {
    textarea: RefObject<HTMLTextAreaElement | null>;
    value: string;
    onChange: (next: string) => void;
    kind: 'page' | 'email';
}) {
    const [open, setOpen] = useState(false);
    // Remembered before the dialog takes focus, which loses the selection.
    const caret = useRef<[number, number] | null>(null);

    const openPicker = () => {
        const el = textarea.current;
        caret.current = el ? [el.selectionStart, el.selectionEnd] : null;
        setOpen(true);
    };

    const insert = (item: MediaItem) => {
        const tag = imageTag(item, kind);
        const [start, end] = caret.current ?? [value.length, value.length];
        onChange(value.slice(0, start) + tag + value.slice(end));

        // Put the cursor just after the new tag.
        requestAnimationFrame(() => {
            const el = textarea.current;

            if (el) {
                el.focus();
                el.setSelectionRange(start + tag.length, start + tag.length);
            }
        });
    };

    return (
        <>
            <button type="button" onClick={openPicker} className="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-3 text-xs font-medium hover:bg-accent">
                <GalleryHorizontalEnd className="size-3.5" /> Insert image
            </button>
            <MediaPicker open={open} onOpenChange={setOpen} onPick={insert} pickLabel="Insert image" />
        </>
    );
}
