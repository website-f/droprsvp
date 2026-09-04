import { Bold, Code, Code2, Eye, Heading1, Heading2, Heading3, Image as ImageIcon, Italic, Link2, List, ListOrdered, Maximize2, Minimize2, Minus, Pilcrow, Quote, RemoveFormatting, Strikethrough, Underline, Youtube } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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

const Btn = ({ onClick, title, children }: { onClick: () => void; title: string; children: React.ReactNode }) => (
    <button type="button" title={title} aria-label={title} onMouseDown={(e) => e.preventDefault()} onClick={onClick}
        className="flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
        {children}
    </button>
);
const Sep = () => <span className="mx-0.5 h-5 w-px shrink-0 bg-border" />;

const isEmptyHtml = (html: string) => {
    const t = html.replace(/<br\s*\/?>/gi, '').replace(/<p>\s*<\/p>/gi, '').replace(/&nbsp;/g, ' ').replace(/<[^>]+>/g, '').trim();

    return t === '' && !/<(img|iframe|hr|table|video)/i.test(html);
};

/** A minimal code editor: line-number gutter + textarea, dark, tab-aware. */
function CodeArea({ value, onChange }: { value: string; onChange: (v: string) => void }) {
    const taRef = useRef<HTMLTextAreaElement>(null);
    const gutterRef = useRef<HTMLDivElement>(null);
    const lineCount = Math.max(1, value.split('\n').length);

    return (
        <div className="flex min-h-80 flex-1 overflow-hidden bg-[#0d1117] font-mono text-[13px] leading-6 text-slate-200">
            <div ref={gutterRef} className="shrink-0 select-none overflow-hidden py-3 pr-2 pl-3 text-right text-slate-500 tabular-nums" aria-hidden>
                {Array.from({ length: lineCount }, (_, i) => <div key={i}>{i + 1}</div>)}
            </div>
            <textarea
                ref={taRef}
                value={value}
                spellCheck={false}
                onScroll={() => {
 if (gutterRef.current && taRef.current) {
gutterRef.current.scrollTop = taRef.current.scrollTop;
} 
}}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Tab') {
                        e.preventDefault();
                        const t = e.currentTarget;
                        const s = t.selectionStart;
                        const en = t.selectionEnd;
                        onChange(value.slice(0, s) + '  ' + value.slice(en));
                        requestAnimationFrame(() => {
 t.selectionStart = t.selectionEnd = s + 2; 
});
                    }
                }}
                className="flex-1 resize-none bg-transparent py-3 pr-3 pl-2 outline-none"
                placeholder="<h2>Paste or write HTML…</h2>"
            />
        </div>
    );
}

/**
 * HTML-first rich text editor. The Visual view is a contenteditable surface, so
 * the browser keeps the actual HTML/DOM — pasted markup (tables, inline styles,
 * custom attributes) survives the save→reload round-trip instead of being flattened
 * by an intermediate model. A line-numbered Code view edits the same raw HTML, and
 * either view can go full-screen. Output HTML is styled by `contentClass`.
 */
export function RichEditor({ value, onChange, placeholder }: { value: string; onChange: (html: string) => void; placeholder?: string }) {
    'use no memo';

    const prompt = usePrompt();
    const editorRef = useRef<HTMLDivElement>(null);
    const onChangeRef = useRef(onChange);
    const promptRef = useRef(prompt);
    useEffect(() => {
        onChangeRef.current = onChange;
        promptRef.current = prompt;
    });

    const [mode, setMode] = useState<'visual' | 'code'>('visual');
    const [full, setFull] = useState(false);

    // Load external value into the visual surface when it changes and we're not
    // actively typing in it (so loading a record doesn't fight the cursor).
    useEffect(() => {
        const el = editorRef.current;

        if (mode !== 'visual' || !el) {
            return;
        }

        if (document.activeElement !== el && (value || '') !== el.innerHTML) {
            el.innerHTML = value || '';
        }
    }, [value, mode]);

    // Prefer <p> paragraphs over <div> when the surface gains focus.
    const onFocus = () => {
        try {
            document.execCommand('defaultParagraphSeparator', false, 'p');
        } catch {
            /* not supported — harmless */
        }
    };

    const emit = () => {
        const el = editorRef.current;

        if (el) {
            const html = el.innerHTML;
            onChangeRef.current(isEmptyHtml(html) ? '' : html);
        }
    };

    // execCommand is deprecated but is the only cross-browser way to format a
    // contenteditable in place; it's more than enough for a CMS editor.
    const exec = (cmd: string, arg?: string) => {
        editorRef.current?.focus();
        document.execCommand(cmd, false, arg);
        emit();
    };
    const block = (tag: string) => exec('formatBlock', tag);

    const insertHtml = (html: string) => exec('insertHTML', html);

    const link = () => {
        promptRef.current({ title: 'Insert link', label: 'URL', placeholder: 'https://…', confirmText: 'Insert' }).then((url) => {
            if (url) {
                exec('createLink', url.trim());
            }
        });
    };

    const video = () => {
        promptRef.current({ title: 'Insert YouTube video', label: 'YouTube link', placeholder: 'https://www.youtube.com/watch?v=…', confirmText: 'Insert' }).then((url) => {
            if (!url) {
                return;
            }

            const id = youtubeId(url.trim());
            const src = id ? `https://www.youtube.com/embed/${id}` : url.trim();
            insertHtml(`<iframe src="${src}" allowfullscreen frameborder="0"></iframe><p><br></p>`);
        });
    };

    const image = () => {
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
                insertHtml(`<img src="${url}" alt="">`);
            } catch {
                /* ignore */
            }
        };
        input.click();
    };

    const toCode = () => setMode('code');
    const toVisual = () => {
        if (editorRef.current) {
            editorRef.current.innerHTML = value || '';
        }

        setMode('visual');
    };

    const empty = isEmptyHtml(value || '');

    return (
        <div className={`rte flex flex-col overflow-hidden rounded-xl border border-input bg-card shadow-sm ${full ? 'fixed inset-0 z-[100] rounded-none' : ''}`}>
            {/* Toolbar */}
            <div className="flex flex-wrap items-center gap-0.5 border-b border-input bg-muted/40 px-2 py-1.5">
                {mode === 'visual' && (
                    <>
                        <Btn onClick={() => block('P')} title="Paragraph"><Pilcrow className="size-4" /></Btn>
                        <Btn onClick={() => block('H1')} title="Heading 1"><Heading1 className="size-4" /></Btn>
                        <Btn onClick={() => block('H2')} title="Heading 2"><Heading2 className="size-4" /></Btn>
                        <Btn onClick={() => block('H3')} title="Heading 3"><Heading3 className="size-4" /></Btn>
                        <Sep />
                        <Btn onClick={() => exec('bold')} title="Bold"><Bold className="size-4" /></Btn>
                        <Btn onClick={() => exec('italic')} title="Italic"><Italic className="size-4" /></Btn>
                        <Btn onClick={() => exec('underline')} title="Underline"><Underline className="size-4" /></Btn>
                        <Btn onClick={() => exec('strikeThrough')} title="Strikethrough"><Strikethrough className="size-4" /></Btn>
                        <Sep />
                        <Btn onClick={() => exec('insertUnorderedList')} title="Bulleted list"><List className="size-4" /></Btn>
                        <Btn onClick={() => exec('insertOrderedList')} title="Numbered list"><ListOrdered className="size-4" /></Btn>
                        <Btn onClick={() => block('BLOCKQUOTE')} title="Quote"><Quote className="size-4" /></Btn>
                        <Btn onClick={() => block('PRE')} title="Code block"><Code className="size-4" /></Btn>
                        <Sep />
                        <Btn onClick={link} title="Link"><Link2 className="size-4" /></Btn>
                        <Btn onClick={image} title="Image"><ImageIcon className="size-4" /></Btn>
                        <Btn onClick={video} title="YouTube video"><Youtube className="size-4" /></Btn>
                        <Btn onClick={() => exec('insertHorizontalRule')} title="Divider"><Minus className="size-4" /></Btn>
                        <Btn onClick={() => exec('removeFormat')} title="Clear formatting"><RemoveFormatting className="size-4" /></Btn>
                    </>
                )}

                <div className="ml-auto flex items-center gap-0.5">
                    <button type="button" onClick={mode === 'visual' ? toCode : toVisual}
                        className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
                        {mode === 'visual' ? <><Code2 className="size-3.5" /> HTML</> : <><Eye className="size-3.5" /> Visual</>}
                    </button>
                    <Btn onClick={() => setFull((f) => !f)} title={full ? 'Exit full screen' : 'Full screen'}>
                        {full ? <Minimize2 className="size-4" /> : <Maximize2 className="size-4" />}
                    </Btn>
                </div>
            </div>

            {/* Visual surface (kept mounted; hidden in code mode so its DOM isn't lost) */}
            <div className={`relative flex-1 overflow-auto ${mode === 'code' ? 'hidden' : ''}`}>
                {empty && mode === 'visual' && (
                    <span className="pointer-events-none absolute top-4 left-4 text-[15px] text-muted-foreground/60">{placeholder ?? 'Write your content…'}</span>
                )}
                <div
                    ref={editorRef}
                    contentEditable
                    suppressContentEditableWarning
                    onFocus={onFocus}
                    onInput={emit}
                    onBlur={emit}
                    className={`min-h-80 w-full px-4 py-3 outline-none ${contentClass} ${full ? 'min-h-[calc(100vh-3rem)]' : ''}`}
                />
            </div>

            {/* Code surface */}
            {mode === 'code' && (
                <CodeArea value={value || ''} onChange={(v) => onChangeRef.current(isEmptyHtml(v) ? '' : v)} />
            )}
        </div>
    );
}
