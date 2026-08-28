import { useEffect, useRef } from 'react';
import Quill from 'quill';
import 'quill/dist/quill.snow.css';
import { uploadImage } from '@/lib/upload';

/** Shared styling for rendered rich-text content on public pages. */
export const contentClass =
    'text-[15px] leading-relaxed text-foreground/90 ' +
    '[&_h1]:mt-8 [&_h1]:text-3xl [&_h1]:font-bold ' +
    '[&_h2]:mt-8 [&_h2]:text-2xl [&_h2]:font-bold [&_h2]:tracking-tight ' +
    '[&_h3]:mt-6 [&_h3]:text-lg [&_h3]:font-semibold ' +
    '[&_p]:my-3 ' +
    '[&_ul]:my-3 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:pl-6 ' +
    '[&_blockquote]:my-5 [&_blockquote]:border-l-2 [&_blockquote]:border-foreground/30 [&_blockquote]:pl-4 [&_blockquote]:text-muted-foreground [&_blockquote]:italic ' +
    '[&_a]:underline [&_a]:underline-offset-2 [&_a]:text-primary ' +
    '[&_hr]:my-8 [&_hr]:border-border ' +
    '[&_img]:my-5 [&_img]:rounded-xl [&_img]:border [&_img]:border-border [&_img]:max-w-full [&_img]:h-auto ' +
    '[&_.ql-align-center]:text-center [&_.ql-align-right]:text-right [&_.ql-align-justify]:text-justify';

/**
 * Rich text editor powered by Quill (open-source). Quill creates and owns its
 * own editable DOM, so React never reconciles it — clicking always focuses and
 * typing always works. Output is HTML rendered by `contentClass`.
 */
export function RichEditor({ value, onChange, placeholder }: { value: string; onChange: (html: string) => void; placeholder?: string }) {
    'use no memo';

    const hostRef = useRef<HTMLDivElement>(null);
    const quillRef = useRef<Quill | null>(null);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    useEffect(() => {
        if (!hostRef.current || quillRef.current) return;

        const quill = new Quill(hostRef.current, {
            theme: 'snow',
            placeholder: placeholder ?? 'Write your content…',
            modules: {
                toolbar: {
                    container: [
                        [{ header: [2, 3, false] }],
                        ['bold', 'italic', 'strike'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['blockquote', 'link', 'image'],
                        [{ align: [] }],
                        ['clean'],
                    ],
                    handlers: {
                        image() {
                            const input = document.createElement('input');
                            input.type = 'file';
                            input.accept = 'image/*';
                            input.onchange = async () => {
                                const file = input.files?.[0];
                                if (!file) return;
                                try {
                                    const url = await uploadImage(file);
                                    const range = quill.getSelection(true);
                                    quill.insertEmbed(range.index, 'image', url, 'user');
                                    quill.setSelection(range.index + 1, 0);
                                } catch { /* ignore */ }
                            };
                            input.click();
                        },
                    },
                },
            },
        });
        quillRef.current = quill;

        if (value) quill.clipboard.dangerouslyPasteHTML(value);

        quill.on('text-change', () => {
            const html = quill.getSemanticHTML();
            onChangeRef.current(html === '<p></p>' ? '' : html);
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Sync external value changes (e.g. loading a record) without disturbing typing.
    useEffect(() => {
        const quill = quillRef.current;
        if (quill && !quill.hasFocus() && (value || '') !== quill.getSemanticHTML().replace('<p></p>', '')) {
            quill.clipboard.dangerouslyPasteHTML(value || '');
        }
    }, [value]);

    return (
        <div className="rte overflow-hidden rounded-xl border border-input bg-card shadow-sm">
            <div ref={hostRef} />
        </div>
    );
}
