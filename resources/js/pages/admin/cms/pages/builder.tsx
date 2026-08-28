import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { Editor } from 'grapesjs';
import { Button } from '@/components/ui/button';
import { GrapesBuilder } from '@/components/cms/grapes-builder';
import { ArrowLeft } from 'lucide-react';

interface PageProp { id: number; title: string; slug: string; status: string; html: string; css: string }

export default function DropBuilder({ page }: { page: PageProp }) {
    const [title, setTitle] = useState(page.title);
    const [saving, setSaving] = useState(false);
    const editorRef = useRef<Editor | null>(null);

    const save = () => {
        const ed = editorRef.current;
        if (!ed) return;
        setSaving(true);
        router.post(`/admin/cms/pages/${page.id}/builder`, {
            title,
            html: ed.getHtml(),
            css: ed.getCss() ?? '',
        }, { onFinish: () => setSaving(false) });
    };

    return (
        <>
            <Head title={`Builder · ${page.title}`} />
            <div className="flex h-screen flex-col bg-background text-foreground">
                <header className="flex items-center gap-3 border-b border-border bg-card px-4 py-2.5">
                    <Button asChild variant="ghost" size="icon"><Link href={`/admin/cms/pages/${page.id}/edit`} aria-label="Back to page"><ArrowLeft className="size-4" /></Link></Button>
                    <div className="min-w-0">
                        <input aria-label="Page title" className="w-full min-w-0 border-0 bg-transparent text-sm font-semibold outline-none" value={title} onChange={(e) => setTitle(e.target.value)} />
                        <p className="text-xs text-muted-foreground">Drop Builder</p>
                    </div>
                    <Button size="sm" className="ml-auto" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save'}</Button>
                </header>
                <div className="min-h-0 flex-1">
                    <GrapesBuilder initialHtml={page.html} initialCss={page.css} onEditor={(e) => (editorRef.current = e)} />
                </div>
            </div>
        </>
    );
}
