import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { PageBuilder, normalizeSections, sectionsToHtml } from '@/components/cms/page-builder';
import { PageSections, hasSections, type PageSection } from '@/components/cms/page-sections';
import { ArrowLeft, Eye, Monitor, Pencil, Smartphone, Tablet } from 'lucide-react';

interface PageProp { id: number; title: string; slug: string; status: string; layout: PageSection[] | null }

type Device = 'desktop' | 'tablet' | 'mobile';
const DEVICE_W: Record<Device, string> = { desktop: 'max-w-none', tablet: 'max-w-[768px]', mobile: 'max-w-[390px]' };

export default function DropBuilder({ page }: { page: PageProp }) {
    const [mode, setMode] = useState<'edit' | 'preview'>('edit');
    const [device, setDevice] = useState<Device>('desktop');

    const form = useForm({
        title: page.title,
        layout: normalizeSections(page.layout),
        body: '',
    });
    const { data, setData, processing } = form;

    const save = () => {
        form.transform((d) => ({ ...d, body: sectionsToHtml(d.layout) }));
        form.post(`/admin/cms/pages/${page.id}/builder`);
    };

    return (
        <>
            <Head title={`Drop Builder · ${page.title}`} />
            <div className="flex min-h-screen flex-col bg-muted/40 text-foreground">
                {/* Top bar */}
                <header className="sticky top-0 z-20 flex flex-wrap items-center gap-3 border-b border-border bg-card/95 px-4 py-2.5 backdrop-blur">
                    <Button asChild variant="ghost" size="icon"><Link href={`/admin/cms/pages/${page.id}/edit`} aria-label="Back to page"><ArrowLeft className="size-4" /></Link></Button>
                    <div className="min-w-0">
                        <input
                            aria-label="Page title"
                            className="w-full min-w-0 border-0 bg-transparent text-sm font-semibold outline-none"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">Drop Builder</p>
                    </div>

                    {/* Edit / Preview */}
                    <div className="ml-auto flex items-center rounded-lg border border-border p-0.5">
                        <button type="button" onClick={() => setMode('edit')} className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ${mode === 'edit' ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-accent'}`}><Pencil className="size-3.5" /> Edit</button>
                        <button type="button" onClick={() => setMode('preview')} className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ${mode === 'preview' ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-accent'}`}><Eye className="size-3.5" /> Preview</button>
                    </div>

                    {/* Device toggle (drives the preview width) */}
                    <div className="flex items-center rounded-lg border border-border p-0.5">
                        {([['desktop', Monitor], ['tablet', Tablet], ['mobile', Smartphone]] as [Device, typeof Monitor][]).map(([d, Icon]) => (
                            <button key={d} type="button" aria-label={d} title={d} onClick={() => { setDevice(d); if (d !== 'desktop') setMode('preview'); }} className={`flex size-8 items-center justify-center rounded-md ${device === d ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-accent'}`}><Icon className="size-4" /></button>
                        ))}
                    </div>

                    <Button size="sm" onClick={save} disabled={processing}>{processing ? 'Saving…' : 'Save'}</Button>
                </header>

                {/* Canvas */}
                <div className="flex-1 overflow-auto p-4 sm:p-6">
                    {mode === 'edit' ? (
                        <div className="mx-auto max-w-5xl">
                            <PageBuilder value={data.layout} onChange={(s) => setData('layout', s)} />
                        </div>
                    ) : (
                        <div className={`mx-auto w-full ${DEVICE_W[device]} transition-[max-width] duration-300`}>
                            <div className="overflow-hidden rounded-2xl border border-border bg-background shadow-sm">
                                <h1 className="px-6 pt-8 text-3xl font-bold tracking-tight sm:px-10">{data.title || 'Untitled page'}</h1>
                                <div className="px-2 py-6 sm:px-6">
                                    {hasSections(data.layout)
                                        ? <PageSections sections={data.layout} />
                                        : <p className="px-4 text-sm text-muted-foreground">Nothing to preview yet — switch to Edit and add a section.</p>}
                                </div>
                            </div>
                            {device !== 'desktop' && <p className="mt-3 text-center text-xs text-muted-foreground">{device === 'mobile' ? 'Mobile' : 'Tablet'} preview — {device === 'mobile' ? '390' : '768'}px</p>}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
