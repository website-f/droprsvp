import { useEffect, useRef, useState } from 'react';
import { uploadImage } from '@/lib/upload';
import {
    AlignCenter, AlignLeft, AlignRight, Bold, Heading2, Heading3, ImagePlus,
    Italic, Link as LinkIcon, List, ListOrdered, Minus, Quote, Redo, Strikethrough, Undo,
} from 'lucide-react';

/** Shared styling for rendered rich-text content (editor + public page). */
export const contentClass =
    'text-[15px] leading-relaxed text-foreground/90 ' +
    '[&_h2]:mt-8 [&_h2]:text-2xl [&_h2]:font-bold [&_h2]:tracking-tight ' +
    '[&_h3]:mt-6 [&_h3]:text-lg [&_h3]:font-semibold ' +
    '[&_p]:my-3 ' +
    '[&_ul]:my-3 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:pl-6 ' +
    '[&_blockquote]:my-5 [&_blockquote]:border-l-2 [&_blockquote]:border-foreground/30 [&_blockquote]:pl-4 [&_blockquote]:text-muted-foreground [&_blockquote]:italic ' +
    '[&_a]:underline [&_a]:underline-offset-2 ' +
    '[&_hr]:my-8 [&_hr]:border-border ' +
    '[&_img]:my-5 [&_img]:rounded-xl [&_img]:border [&_img]:border-border [&_img]:max-w-full [&_img]:h-auto ' +
    '[&_[style*="text-align:center"]]:text-center [&_[style*="text-align:right"]]:text-right ' +
    '[&_[style*="text-align: center"]]:text-center [&_[style*="text-align: right"]]:text-right';

function Btn({ on, active, disabled, children, label }: { on: () => void; active?: boolean; disabled?: boolean; children: React.ReactNode; label: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            disabled={disabled}
            // Use onMouseDown + preventDefault so the button never steals the
            // editor selection/focus before the command runs.
            onMouseDown={(e) => { e.preventDefault(); if (!disabled) on(); }}
            className={`flex size-8 items-center justify-center rounded-md text-sm transition-colors disabled:opacity-40 ${active ? 'bg-foreground text-background' : 'hover:bg-accent'}`}
        >
            {children}
        </button>
    );
}

function Sep() {
    return <span className="mx-1 h-5 w-px shrink-0 bg-border" />;
}

/**
 * A dependency-free rich-text editor built on a native contentEditable div.
 * Clicking it always focuses and types (no editor-library quirks). Output is
 * HTML, styled by `contentClass` — identical in the editor and on public pages.
 */
export function RichEditor({ value, onChange, placeholder }: { value: string; onChange: (html: string) => void; placeholder?: string }) {
    const ref = useRef<HTMLDivElement>(null);
    const imgRef = useRef<HTMLInputElement>(null);
    const [active, setActive] = useState<Record<string, boolean>>({});
    const [uploading, setUploading] = useState(false);

    // Initialise content once on mount.
    useEffect(() => {
        if (ref.current) {
            ref.current.innerHTML = value || '';
            syncEmpty();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Sync when the value changes externally (e.g. form reset) — but never while
    // the user is typing, which would reset the caret.
    useEffect(() => {
        const el = ref.current;
        if (el && document.activeElement !== el && (value || '') !== el.innerHTML) {
            el.innerHTML = value || '';
            syncEmpty();
        }
    }, [value]);

    const syncEmpty = () => {
        const el = ref.current;
        if (el) el.dataset.empty = String(el.textContent?.trim() === '' && !el.querySelector('img,hr'));
    };

    const emit = () => { onChange(ref.current?.innerHTML || ''); syncEmpty(); };

    const refreshActive = () => {
        try {
            setActive({
                bold: document.queryCommandState('bold'),
                italic: document.queryCommandState('italic'),
                strikeThrough: document.queryCommandState('strikeThrough'),
                insertUnorderedList: document.queryCommandState('insertUnorderedList'),
                insertOrderedList: document.queryCommandState('insertOrderedList'),
            });
        } catch { /* queryCommandState can throw when unfocused */ }
    };

    const exec = (command: string, arg?: string) => {
        ref.current?.focus();
        document.execCommand('styleWithCSS', false, 'true');
        document.execCommand(command, false, arg);
        emit();
        refreshActive();
    };
    const block = (tag: string) => exec('formatBlock', tag);

    const addLink = () => {
        const url = window.prompt('Link URL');
        if (url) exec('createLink', url);
    };

    const addImage = async (file: File | undefined) => {
        if (!file) return;
        setUploading(true);
        try {
            const url = await uploadImage(file);
            exec('insertImage', url);
        } catch { /* ignore */ } finally { setUploading(false); }
    };

    return (
        <div className="overflow-hidden rounded-xl border border-input bg-card shadow-sm transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/20">
            {/* Toolbar */}
            <div className="sticky top-16 z-10 flex flex-wrap items-center gap-1 rounded-t-xl border-b border-border bg-card/95 p-2 backdrop-blur">
                <Btn label="Bold" on={() => exec('bold')} active={active.bold}><Bold className="size-4" /></Btn>
                <Btn label="Italic" on={() => exec('italic')} active={active.italic}><Italic className="size-4" /></Btn>
                <Btn label="Strikethrough" on={() => exec('strikeThrough')} active={active.strikeThrough}><Strikethrough className="size-4" /></Btn>
                <Sep />
                <Btn label="Heading" on={() => block('H2')}><Heading2 className="size-4" /></Btn>
                <Btn label="Subheading" on={() => block('H3')}><Heading3 className="size-4" /></Btn>
                <Btn label="Bullet list" on={() => exec('insertUnorderedList')} active={active.insertUnorderedList}><List className="size-4" /></Btn>
                <Btn label="Numbered list" on={() => exec('insertOrderedList')} active={active.insertOrderedList}><ListOrdered className="size-4" /></Btn>
                <Btn label="Quote" on={() => block('BLOCKQUOTE')}><Quote className="size-4" /></Btn>
                <Sep />
                <Btn label="Align left" on={() => exec('justifyLeft')}><AlignLeft className="size-4" /></Btn>
                <Btn label="Align center" on={() => exec('justifyCenter')}><AlignCenter className="size-4" /></Btn>
                <Btn label="Align right" on={() => exec('justifyRight')}><AlignRight className="size-4" /></Btn>
                <Sep />
                <Btn label="Link" on={addLink}><LinkIcon className="size-4" /></Btn>
                <Btn label={uploading ? 'Uploading image…' : 'Insert image'} disabled={uploading} on={() => imgRef.current?.click()}><ImagePlus className="size-4" /></Btn>
                <Btn label="Section divider" on={() => exec('insertHorizontalRule')}><Minus className="size-4" /></Btn>
                <input ref={imgRef} type="file" accept="image/*" hidden onChange={(e) => { addImage(e.target.files?.[0]); e.target.value = ''; }} />
                <Sep />
                <Btn label="Undo" on={() => exec('undo')}><Undo className="size-4" /></Btn>
                <Btn label="Redo" on={() => exec('redo')}><Redo className="size-4" /></Btn>
            </div>

            {/* Editable surface */}
            <div
                ref={ref}
                contentEditable
                suppressContentEditableWarning
                role="textbox"
                aria-multiline="true"
                data-placeholder={placeholder ?? 'Write your content…'}
                className={`${contentClass} rte-content min-h-[360px] cursor-text px-5 py-4 outline-none`}
                onInput={emit}
                onBlur={emit}
                onKeyUp={refreshActive}
                onMouseUp={refreshActive}
                onFocus={refreshActive}
            />
        </div>
    );
}
