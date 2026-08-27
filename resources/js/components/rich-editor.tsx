import { EditorContent, useEditor, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import LinkExt from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import { Bold, Heading2, Italic, Link as LinkIcon, List, ListOrdered, Quote, Redo, Undo } from 'lucide-react';

/** Shared styling for rendered rich-text content (editor + public page). */
export const contentClass =
    'text-[15px] leading-relaxed text-foreground/90 [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-bold [&_h3]:mt-5 [&_h3]:text-lg [&_h3]:font-semibold [&_p]:my-3 [&_ul]:my-3 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:pl-6 [&_blockquote]:my-4 [&_blockquote]:border-l-2 [&_blockquote]:border-border [&_blockquote]:pl-4 [&_blockquote]:text-muted-foreground [&_a]:underline [&_a]:underline-offset-2';

function Btn({ on, active, children, label }: { on: () => void; active?: boolean; children: React.ReactNode; label: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={on}
            className={`flex size-8 items-center justify-center rounded-md text-sm transition-colors ${active ? 'bg-foreground text-background' : 'hover:bg-accent'}`}
        >
            {children}
        </button>
    );
}

function Toolbar({ editor }: { editor: Editor }) {
    const addLink = () => {
        const url = window.prompt('Link URL');
        if (url === null) return;
        if (url === '') { editor.chain().focus().unsetLink().run(); return; }
        editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    };
    return (
        <div className="flex flex-wrap items-center gap-1 border-b border-border p-2">
            <Btn label="Bold" on={() => editor.chain().focus().toggleBold().run()} active={editor.isActive('bold')}><Bold className="size-4" /></Btn>
            <Btn label="Italic" on={() => editor.chain().focus().toggleItalic().run()} active={editor.isActive('italic')}><Italic className="size-4" /></Btn>
            <Btn label="Heading" on={() => editor.chain().focus().toggleHeading({ level: 2 }).run()} active={editor.isActive('heading', { level: 2 })}><Heading2 className="size-4" /></Btn>
            <Btn label="Bullet list" on={() => editor.chain().focus().toggleBulletList().run()} active={editor.isActive('bulletList')}><List className="size-4" /></Btn>
            <Btn label="Numbered list" on={() => editor.chain().focus().toggleOrderedList().run()} active={editor.isActive('orderedList')}><ListOrdered className="size-4" /></Btn>
            <Btn label="Quote" on={() => editor.chain().focus().toggleBlockquote().run()} active={editor.isActive('blockquote')}><Quote className="size-4" /></Btn>
            <Btn label="Link" on={addLink} active={editor.isActive('link')}><LinkIcon className="size-4" /></Btn>
            <span className="mx-1 h-5 w-px bg-border" />
            <Btn label="Undo" on={() => editor.chain().focus().undo().run()}><Undo className="size-4" /></Btn>
            <Btn label="Redo" on={() => editor.chain().focus().redo().run()}><Redo className="size-4" /></Btn>
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
            Placeholder.configure({ placeholder: placeholder ?? 'Write your content…' }),
        ],
        content: value || '',
        onUpdate: ({ editor }) => onChange(editor.getHTML()),
        editorProps: { attributes: { class: `${contentClass} min-h-[280px] px-4 py-3 focus:outline-none` } },
    });

    return (
        <div className="rounded-lg border border-input bg-card">
            {editor && <Toolbar editor={editor} />}
            <EditorContent editor={editor} />
        </div>
    );
}
