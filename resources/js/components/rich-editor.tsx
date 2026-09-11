import { AlignCenter, AlignLeft, AlignRight, Bold, Code, Code2, Eye, Image as ImageIcon, Italic, Link2, List, ListOrdered, ListTree, Maximize2, Minimize2, Minus, Quote, RemoveFormatting, Strikethrough, Table, Underline, Youtube } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { usePrompt } from '@/components/prompt-dialog';
import { uploadImageWithToast } from '@/lib/upload';

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
    '[&_.ql-align-center]:text-center [&_.ql-align-right]:text-right [&_.ql-align-justify]:text-justify ' +
    // Table of contents — both the editor's placeholder block and the rendered
    // <nav class="post-toc"> the server swaps in for it on the public page.
    '[&_.post-toc]:my-6 [&_.post-toc]:rounded-xl [&_.post-toc]:border [&_.post-toc]:border-border [&_.post-toc]:bg-muted/40 [&_.post-toc]:p-4 [&_.post-toc]:sm:p-5 ' +
    '[&_.post-toc-title]:m-0 [&_.post-toc-title]:text-xs [&_.post-toc-title]:font-semibold [&_.post-toc-title]:uppercase [&_.post-toc-title]:tracking-wider [&_.post-toc-title]:text-muted-foreground ' +
    '[&_.post-toc_ol]:mt-3 [&_.post-toc_ol]:mb-0 [&_.post-toc_ol]:list-none [&_.post-toc_ol]:pl-0 [&_.post-toc_ol]:space-y-1.5 ' +
    '[&_.post-toc_li]:my-0 [&_.post-toc-l3]:pl-5 ' +
    '[&_.post-toc_a]:text-foreground/80 [&_.post-toc_a]:no-underline hover:[&_.post-toc_a]:text-foreground hover:[&_.post-toc_a]:underline ' +
    // Headings are anchor targets — keep them clear of a sticky header on jump.
    '[&_h2]:scroll-mt-24 [&_h3]:scroll-mt-24';

const Btn = ({ onClick, title, active, children }: { onClick: () => void; title: string; active?: boolean; children: React.ReactNode }) => (
    <button type="button" title={title} aria-label={title} aria-pressed={active} onMouseDown={(e) => e.preventDefault()} onClick={onClick}
        className={`flex size-8 items-center justify-center rounded-md transition-colors ${active ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-accent hover:text-foreground'}`}>
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
    // Which inline/block commands are active for the current selection (toolbar state).
    const [active, setActive] = useState<Record<string, boolean>>({});
    const [blockTag, setBlockTag] = useState('');

    // Reflect the caret's formatting back into the toolbar so buttons light up.
    const syncState = () => {
        const el = editorRef.current;

        if (!el) {
            return;
        }

        const sel = window.getSelection();

        if (!sel || !sel.anchorNode || !el.contains(sel.anchorNode)) {
            return; // caret isn't inside the editor
        }

        const state: Record<string, boolean> = {};

        for (const cmd of ['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList', 'justifyLeft', 'justifyCenter', 'justifyRight']) {
            try {
                state[cmd] = document.queryCommandState(cmd);
            } catch {
                state[cmd] = false;
            }
        }

        setActive(state);

        try {
            setBlockTag((document.queryCommandValue('formatBlock') || '').toString().toUpperCase());
        } catch {
            setBlockTag('');
        }
    };

    // Track selection changes while the editor is focused.
    useEffect(() => {
        const handler = () => syncState();
        document.addEventListener('selectionchange', handler);

        return () => document.removeEventListener('selectionchange', handler);
         
    }, []);

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
        syncState();
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

            const url = await uploadImageWithToast(file);

            if (url) {
                insertHtml(`<img src="${url}" alt="">`);
            }
        };
        input.click();
    };

    /**
     * Drop in a contents placeholder. It stays a simple marker in the stored
     * HTML — the server (App\Support\TableOfContents) swaps it for the real list
     * at render time, so the contents can never drift from the headings.
     */
    const toc = () => {
        if ((value || '').includes('data-toc')) {
            return; // one per post
        }

        insertHtml('<div data-toc="1" class="post-toc"><p class="post-toc-title">Table of Contents</p><p>Built automatically from this post’s H2 and H3 headings.</p></div><p><br></p>');
    };

    const table = () => {
        const cell = '<td>&nbsp;</td>';
        const head = '<th>Heading</th>';
        insertHtml(
            '<table><thead><tr>' + head.repeat(3) + '</tr></thead><tbody>'
            + ('<tr>' + cell.repeat(3) + '</tr>').repeat(2)
            + '</tbody></table><p><br></p>',
        );
    };

    const toCode = () => setMode('code');
    const toVisual = () => {
        if (editorRef.current) {
            editorRef.current.innerHTML = value || '';
        }

        setMode('visual');
    };

    const empty = isEmptyHtml(value || '');
    const hasToc = (value || '').includes('data-toc');

    // Live word count + reading estimate, the way WordPress shows them.
    const words = (() => {
        const text = (value || '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').trim();

        return text ? text.split(/\s+/).length : 0;
    })();

    return (
        <div className={`rte flex flex-col overflow-hidden rounded-xl border border-input bg-card shadow-sm ${full ? 'fixed inset-0 z-40 rounded-none' : ''}`}>
            {/* Toolbar — sticks to the top of the editor while writing, and scrolls
                sideways on narrow screens rather than stacking into a tall block. */}
            <div className="sticky top-0 z-10 flex items-center gap-0.5 overflow-x-auto border-b border-input bg-muted/60 px-2 py-1.5 backdrop-blur [scrollbar-width:thin] md:flex-wrap md:overflow-visible">
                {mode === 'visual' && (
                    <>
                        {/* Paragraph / heading level (P + H1–H6) — reflects the caret's block */}
                        <select
                            title="Text style"
                            value={['H1', 'H2', 'H3', 'H4', 'H5', 'H6'].includes(blockTag) ? blockTag : 'P'}
                            onMouseDown={(e) => e.stopPropagation()}
                            onChange={(e) => block(e.target.value)}
                            className="h-8 rounded-md border border-input bg-card px-2 text-sm text-foreground outline-none hover:bg-accent">
                            <option value="P">Paragraph</option>
                            <option value="H1">Heading 1</option>
                            <option value="H2">Heading 2</option>
                            <option value="H3">Heading 3</option>
                            <option value="H4">Heading 4</option>
                            <option value="H5">Heading 5</option>
                            <option value="H6">Heading 6</option>
                        </select>
                        <Sep />
                        <Btn onClick={() => exec('bold')} title="Bold" active={active.bold}><Bold className="size-4" /></Btn>
                        <Btn onClick={() => exec('italic')} title="Italic" active={active.italic}><Italic className="size-4" /></Btn>
                        <Btn onClick={() => exec('underline')} title="Underline" active={active.underline}><Underline className="size-4" /></Btn>
                        <Btn onClick={() => exec('strikeThrough')} title="Strikethrough" active={active.strikeThrough}><Strikethrough className="size-4" /></Btn>
                        <Sep />
                        <Btn onClick={() => exec('justifyLeft')} title="Align left" active={active.justifyLeft}><AlignLeft className="size-4" /></Btn>
                        <Btn onClick={() => exec('justifyCenter')} title="Align center" active={active.justifyCenter}><AlignCenter className="size-4" /></Btn>
                        <Btn onClick={() => exec('justifyRight')} title="Align right" active={active.justifyRight}><AlignRight className="size-4" /></Btn>
                        <Sep />
                        <Btn onClick={() => exec('insertUnorderedList')} title="Bulleted list" active={active.insertUnorderedList}><List className="size-4" /></Btn>
                        <Btn onClick={() => exec('insertOrderedList')} title="Numbered list" active={active.insertOrderedList}><ListOrdered className="size-4" /></Btn>
                        <Btn onClick={() => block('BLOCKQUOTE')} title="Quote" active={blockTag === 'BLOCKQUOTE'}><Quote className="size-4" /></Btn>
                        <Btn onClick={() => block('PRE')} title="Code block" active={blockTag === 'PRE'}><Code className="size-4" /></Btn>
                        <Sep />
                        <Btn onClick={link} title="Link"><Link2 className="size-4" /></Btn>
                        <Btn onClick={image} title="Image"><ImageIcon className="size-4" /></Btn>
                        <Btn onClick={video} title="YouTube video"><Youtube className="size-4" /></Btn>
                        <Btn onClick={table} title="Insert table"><Table className="size-4" /></Btn>
                        <Btn onClick={() => exec('insertHorizontalRule')} title="Divider"><Minus className="size-4" /></Btn>
                        <Sep />
                        <Btn onClick={toc} title="Table of contents" active={hasToc}><ListTree className="size-4" /></Btn>
                        <Btn onClick={() => exec('removeFormat')} title="Clear formatting"><RemoveFormatting className="size-4" /></Btn>
                    </>
                )}

                <div className="ml-auto flex shrink-0 items-center gap-0.5">
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

            {/* Status bar */}
            <div className="flex items-center gap-3 border-t border-input bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground">
                <span>{words.toLocaleString()} {words === 1 ? 'word' : 'words'}</span>
                <span aria-hidden>·</span>
                <span>{Math.max(1, Math.ceil(words / 200))} min read</span>
                {hasToc && <><span aria-hidden>·</span><span className="inline-flex items-center gap-1"><ListTree className="size-3.5" /> Contents block</span></>}
                <span className="ml-auto hidden sm:inline">{mode === 'visual' ? 'Visual' : 'HTML'}</span>
            </div>
        </div>
    );
}
