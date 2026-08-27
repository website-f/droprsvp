import { EditorContent, useEditor, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import LinkExt from '@tiptap/extension-link';
import ImageExt from '@tiptap/extension-image';
import TextAlign from '@tiptap/extension-text-align';
import Placeholder from '@tiptap/extension-placeholder';
import { useRef, useState } from 'react';
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
    '[&_[style*="text-align:center"]]:text-center [&_[style*="text-align:right"]]:text-right';

function Btn({ on, active, disabled, children, label }: { on: () => void; active?: boolean; disabled?: boolean; children: React.ReactNode; label: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={on}
            className={`flex size-8 items-center justify-center rounded-md text-sm transition-colors disabled:opacity-40 ${active ? 'bg-foreground text-background' : 'hover:bg-accent'}`}
        >
            {children}
        </button>
    );
}

function Sep() {
    return <span className="mx-1 h-5 w-px shrink-0 bg-border" />;
}

function Toolbar({ editor }: { editor: Editor }) {
    const imgRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const addLink = () => {
        const url = window.prompt('Link URL');
        if (url === null) return;
        if (url === '') { editor.chain().focus().unsetLink().run(); return; }
        editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    };

    const addImage = async (file: File | undefined) => {
        if (!file) return;
        setUploading(true);
        try {
            const url = await uploadImage(file);
            editor.chain().focus().setImage({ src: url }).run();
        } catch {
            /* leave the document untouched on failure */
        } finally {
            setUploading(false);
        }
    };

    return (
        <div className="sticky top-16 z-10 flex flex-wrap items-center gap-1 rounded-t-xl border-b border-border bg-card/95 p-2 backdrop-blur">
            <Btn label="Bold" on={() => editor.chain().focus().toggleBold().run()} active={editor.isActive('bold')}><Bold className="size-4" /></Btn>
            <Btn label="Italic" on={() => editor.chain().focus().toggleItalic().run()} active={editor.isActive('italic')}><Italic className="size-4" /></Btn>
            <Btn label="Strikethrough" on={() => editor.chain().focus().toggleStrike().run()} active={editor.isActive('strike')}><Strikethrough className="size-4" /></Btn>
            <Sep />
            <Btn label="Heading" on={() => editor.chain().focus().toggleHeading({ level: 2 }).run()} active={editor.isActive('heading', { level: 2 })}><Heading2 className="size-4" /></Btn>
            <Btn label="Subheading" on={() => editor.chain().focus().toggleHeading({ level: 3 }).run()} active={editor.isActive('heading', { level: 3 })}><Heading3 className="size-4" /></Btn>
            <Btn label="Bullet list" on={() => editor.chain().focus().toggleBulletList().run()} active={editor.isActive('bulletList')}><List className="size-4" /></Btn>
            <Btn label="Numbered list" on={() => editor.chain().focus().toggleOrderedList().run()} active={editor.isActive('orderedList')}><ListOrdered className="size-4" /></Btn>
            <Btn label="Quote" on={() => editor.chain().focus().toggleBlockquote().run()} active={editor.isActive('blockquote')}><Quote className="size-4" /></Btn>
            <Sep />
            <Btn label="Align left" on={() => editor.chain().focus().setTextAlign('left').run()} active={editor.isActive({ textAlign: 'left' })}><AlignLeft className="size-4" /></Btn>
            <Btn label="Align center" on={() => editor.chain().focus().setTextAlign('center').run()} active={editor.isActive({ textAlign: 'center' })}><AlignCenter className="size-4" /></Btn>
            <Btn label="Align right" on={() => editor.chain().focus().setTextAlign('right').run()} active={editor.isActive({ textAlign: 'right' })}><AlignRight className="size-4" /></Btn>
            <Sep />
            <Btn label="Link" on={addLink} active={editor.isActive('link')}><LinkIcon className="size-4" /></Btn>
            <Btn label={uploading ? 'Uploading image…' : 'Insert image'} disabled={uploading} on={() => imgRef.current?.click()}><ImagePlus className="size-4" /></Btn>
            <Btn label="Section divider" on={() => editor.chain().focus().setHorizontalRule().run()}><Minus className="size-4" /></Btn>
            <input ref={imgRef} type="file" accept="image/*" hidden onChange={(e) => { addImage(e.target.files?.[0]); e.target.value = ''; }} />
            <Sep />
            <Btn label="Undo" on={() => editor.chain().focus().undo().run()} disabled={!editor.can().undo()}><Undo className="size-4" /></Btn>
            <Btn label="Redo" on={() => editor.chain().focus().redo().run()} disabled={!editor.can().redo()}><Redo className="size-4" /></Btn>
        </div>
    );
}

export function RichEditor({ value, onChange, placeholder }: { value: string; onChange: (html: string) => void; placeholder?: string }) {
    const editor = useEditor({
        // immediatelyRender:false is required for SSR to avoid a hydration mismatch.
        immediatelyRender: false,
        extensions: [
            StarterKit,
            LinkExt.configure({ openOnClick: false, HTMLAttributes: { rel: 'noopener' } }),
            ImageExt.configure({ inline: false, HTMLAttributes: { class: 'rounded-xl' } }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Placeholder.configure({ placeholder: placeholder ?? 'Write your content… use the toolbar to add headings, images and section dividers.' }),
        ],
        content: value || '',
        onUpdate: ({ editor }) => onChange(editor.getHTML()),
        editorProps: { attributes: { class: `${contentClass} min-h-[420px] px-5 py-4 focus:outline-none` } },
    });

    return (
        <div className="overflow-hidden rounded-xl border border-input bg-card shadow-sm">
            {editor && <Toolbar editor={editor} />}
            <EditorContent editor={editor} />
        </div>
    );
}
