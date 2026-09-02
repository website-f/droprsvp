import { Code2, Eye } from 'lucide-react';
import Quill from 'quill';
import { useEffect, useRef, useState } from 'react';
import 'quill/dist/quill.snow.css';
import { usePrompt } from '@/components/prompt-dialog';
import { uploadImage } from '@/lib/upload';

/** Pull the 11-char video id out of any common YouTube URL shape. */
function youtubeId(url: string): string | null {
    const m = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/|v\/)|youtu\.be\/)([\w-]{11})/);

    return m ? m[1] : null;
}

/**
 * Shared styling for rendered rich-text content on public pages. Kept in lockstep
 * with the editor so the live site matches what was authored. Long words/URLs
 * wrap, media is constrained to the container, and wide code/tables scroll rather
 * than overflowing the page.
 */
export const contentClass =
    'text-[15px] leading-relaxed text-foreground/90 [overflow-wrap:anywhere] ' +
    '[&_h1]:mt-8 [&_h1]:text-4xl [&_h1]:font-bold [&_h1]:tracking-tight ' +
    '[&_h2]:mt-8 [&_h2]:text-3xl [&_h2]:font-bold [&_h2]:tracking-tight ' +
    '[&_h3]:mt-6 [&_h3]:text-2xl [&_h3]:font-semibold ' +
    '[&_h4]:mt-6 [&_h4]:text-xl [&_h4]:font-semibold ' +
    '[&_h5]:mt-4 [&_h5]:text-lg [&_h5]:font-semibold ' +
    '[&_h6]:mt-4 [&_h6]:text-base [&_h6]:font-semibold [&_h6]:uppercase [&_h6]:tracking-wide ' +
    '[&_p]:my-3 [&_p]:max-w-full ' +
    '[&_ul]:my-3 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:pl-6 ' +
    '[&_li]:my-1 ' +
    '[&_blockquote]:my-5 [&_blockquote]:border-l-2 [&_blockquote]:border-foreground/30 [&_blockquote]:pl-4 [&_blockquote]:text-muted-foreground [&_blockquote]:italic ' +
    '[&_a]:underline [&_a]:underline-offset-2 [&_a]:text-primary [&_a]:break-words ' +
    '[&_hr]:my-8 [&_hr]:border-border ' +
    '[&_img]:my-5 [&_img]:rounded-xl [&_img]:border [&_img]:border-border [&_img]:max-w-full [&_img]:h-auto ' +
    '[&_iframe]:my-5 [&_iframe]:aspect-video [&_iframe]:w-full [&_iframe]:rounded-xl [&_iframe]:border [&_iframe]:border-border [&_video]:max-w-full ' +
    '[&_pre]:my-5 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-muted [&_pre]:p-4 [&_pre]:text-sm ' +
    '[&_code]:rounded [&_code]:bg-muted [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:text-[0.9em] [&_pre_code]:bg-transparent [&_pre_code]:p-0 ' +
    '[&_table]:my-5 [&_table]:block [&_table]:w-full [&_table]:overflow-x-auto [&_table]:border-collapse ' +
    '[&_th]:border [&_th]:border-border [&_th]:px-3 [&_th]:py-2 [&_th]:text-left [&_td]:border [&_td]:border-border [&_td]:px-3 [&_td]:py-2 ' +
    '[&_.ql-align-center]:text-center [&_.ql-align-right]:text-right [&_.ql-align-justify]:text-justify';

/**
 * Rich text editor powered by Quill (open-source). Quill creates and owns its
 * own editable DOM, so React never reconciles it — clicking always focuses and
 * typing always works. Output is HTML rendered by `contentClass`. A "HTML" toggle
 * lets authors drop into the raw source (paste/clean markup) and back.
 */
export function RichEditor({ value, onChange, placeholder }: { value: string; onChange: (html: string) => void; placeholder?: string }) {
    'use no memo';

    const prompt = usePrompt();
    const hostRef = useRef<HTMLDivElement>(null);
    const quillRef = useRef<Quill | null>(null);
    const onChangeRef = useRef(onChange);
    const promptRef = useRef(prompt);
    useEffect(() => {
        onChangeRef.current = onChange;
        promptRef.current = prompt;
    });

    const [htmlMode, setHtmlMode] = useState(false);
    const [htmlDraft, setHtmlDraft] = useState('');

    useEffect(() => {
        if (!hostRef.current || quillRef.current) {
return;
}

        const quill = new Quill(hostRef.current, {
            theme: 'snow',
            placeholder: placeholder ?? 'Write your content…',
            modules: {
                toolbar: {
                    container: [
                        [{ header: [1, 2, 3, 4, 5, 6, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['blockquote', 'code-block', 'link', 'image', 'video'],
                        [{ align: [] }],
                        ['clean'],
                    ],
                    handlers: {
                        // Insert a YouTube link as a playable, responsive embed.
                        video() {
                            const range = quill.getSelection(true);
                            promptRef.current({ title: 'Insert YouTube video', label: 'YouTube link', placeholder: 'https://www.youtube.com/watch?v=…', confirmText: 'Insert' }).then((url) => {
                                if (!url) {
                                    return;
                                }

                                const id = youtubeId(url.trim());
                                const src = id ? `https://www.youtube.com/embed/${id}` : url.trim();
                                quill.insertEmbed(range.index, 'video', src, 'user');
                                quill.setSelection(range.index + 1, 0);
                            });
                        },
                        image() {
                            const input = document.createElement('input');
                            input.type = 'file';
                            input.accept = 'image/*';
                            input.onchange = async () => {
                                const file = input.files?.[0];

                                if (!file) {
return;
}

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

        if (value) {
quill.clipboard.dangerouslyPasteHTML(value);
}

        quill.on('text-change', () => {
            const html = quill.getSemanticHTML();
            onChangeRef.current(html === '<p></p>' ? '' : html);
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Sync external value changes (e.g. loading a record) without disturbing typing.
    useEffect(() => {
        const quill = quillRef.current;

        if (htmlMode) {
return;
}

        if (quill && !quill.hasFocus() && (value || '') !== quill.getSemanticHTML().replace('<p></p>', '')) {
            quill.clipboard.dangerouslyPasteHTML(value || '');
        }
    }, [value, htmlMode]);

    // Enter HTML mode: snapshot the current markup into the textarea.
    // Leave HTML mode: push the edited markup back into Quill (which re-emits onChange).
    const toggleHtml = () => {
        const quill = quillRef.current;

        if (!htmlMode) {
            setHtmlDraft(quill ? quill.getSemanticHTML() : value || '');
            setHtmlMode(true);
        } else {
            if (quill) {
quill.clipboard.dangerouslyPasteHTML(htmlDraft || '');
}

            onChangeRef.current(htmlDraft.trim() === '<p></p>' ? '' : htmlDraft);
            setHtmlMode(false);
        }
    };

    return (
        <div className="rte overflow-hidden rounded-xl border border-input bg-card shadow-sm">
            <style>{`.rte .ql-editor .ql-video{display:block;width:100%;aspect-ratio:16/9;height:auto;border-radius:12px;margin:1rem 0;}`}</style>
            <div className="flex items-center justify-end border-b border-input bg-muted/40 px-2 py-1.5">
                <button
                    type="button"
                    onClick={toggleHtml}
                    className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    {htmlMode ? <><Eye className="size-3.5" /> Visual</> : <><Code2 className="size-3.5" /> HTML</>}
                </button>
            </div>
            {/* Quill host stays mounted (it owns its DOM); hidden in HTML mode. */}
            <div className={htmlMode ? 'hidden' : ''}>
                <div ref={hostRef} />
            </div>
            {htmlMode && (
                <textarea
                    value={htmlDraft}
                    onChange={(e) => {
 setHtmlDraft(e.target.value); onChangeRef.current(e.target.value.trim() === '<p></p>' ? '' : e.target.value); 
}}
                    spellCheck={false}
                    className="block h-80 w-full resize-y bg-card p-4 font-mono text-xs leading-relaxed text-foreground outline-none"
                    placeholder="<h2>Paste or write HTML…</h2>"
                />
            )}
        </div>
    );
}
