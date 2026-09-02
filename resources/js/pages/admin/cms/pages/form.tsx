import { Head, useForm } from '@inertiajs/react';
import type {Data} from '@measured/puck';
import { ExternalLink, LayoutTemplate, Loader2 } from 'lucide-react';
import { lazy, Suspense, useEffect, useState } from 'react';
import { EditorShell, SettingsCard } from '@/components/cms/editor-shell';
import type {PostCard} from '@/components/cms/puck-config';
import { SeoFields  } from '@/components/seo-fields';
import type {SeoData} from '@/components/seo-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SwitchField } from '@/components/ui/switch';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

// Heavy (pulls the Puck runtime) — only loaded when a built page is previewed.
const PagePreview = lazy(() => import('@/components/cms/page-preview'));

interface PageProp { id: number; title: string; slug: string; puck: Data | null; status: string; in_menu: boolean; builder_edited_at: string | null; seo: SeoData; posts?: PostCard[] }

const emptySeo = (): SeoData => ({ seo_title: null, meta_description: null, focus_keyphrase: null, meta_keywords: null, canonical_url: null, robots_index: true, robots_follow: true, og_title: null, og_description: null, og_image: null });

export default function PageForm({ page }: { page: PageProp | null }) {
    const isEdit = !!page;
    const [baseUrl] = useState(() => (typeof window !== 'undefined' ? window.location.origin : ''));

    const form = useForm({
        title: page?.title ?? '',
        slug: page?.slug ?? '',
        add_to_menu: page?.in_menu ?? false,
        publish: false,
        seo: page?.seo ?? emptySeo(),
    });
    const { data, setData, processing, errors } = form;
    useUnsavedChanges(form.isDirty && !processing);
    const published = page?.status === 'published';
    const built = !!(page && page.puck && Array.isArray(page.puck.content) && page.puck.content.length > 0);

    // Warm the heavy Drop Builder chunk in the background while the user is on the
    // form, so opening it is instant even on slower devices/connections.
    useEffect(() => {
        const warm = () => {
 import('@/components/cms/builder-canvas'); 
};
        const w = window as unknown as { requestIdleCallback?: (cb: () => void) => number; cancelIdleCallback?: (id: number) => void };
        const id = w.requestIdleCallback ? w.requestIdleCallback(warm) : window.setTimeout(warm, 1500);

        return () => {
            if (w.requestIdleCallback && w.cancelIdleCallback) {
                w.cancelIdleCallback(id);
            } else {
                clearTimeout(id);
            }
        };
    }, []);

    const save = (publish: boolean) => {
        form.transform((d) => ({ ...d, publish }));

        if (isEdit) {
            form.put(`/admin/cms/pages/${page!.id}`);
        } else {
            form.post('/admin/cms/pages');
        }
    };

    // Open the full-screen Drop Builder. New pages save a draft first.
    const openBuilder = () => {
        if (isEdit) {
 window.location.href = `/admin/cms/pages/${page!.id}/builder`;

 return; 
}

        form.transform((d) => ({ ...d, publish: false, open_builder: true }));
        form.post('/admin/cms/pages');
    };

    return (
        <>
            <Head title={isEdit ? 'Edit page' : 'New page'} />
            <EditorShell
                backHref="/admin/cms/pages"
                backLabel="Pages"
                title={data.title || (isEdit ? 'Edit page' : 'New page')}
                status={<Badge variant={published ? 'default' : 'secondary'}>{published ? 'Published' : 'Draft'}</Badge>}
                actions={
                    <>
                        <Button variant="outline" size="sm" disabled={processing} onClick={() => save(false)}>Save draft</Button>
                        <Button size="sm" disabled={processing} onClick={() => save(true)}>{published ? 'Update' : 'Publish'}</Button>
                    </>
                }
                sidebar={
                    <>
                        <SettingsCard title="Publish">
                            <p className="text-sm text-muted-foreground">
                                {published ? 'This page is live.' : 'This page is a draft and not visible publicly.'}
                            </p>
                            <div className="rounded-lg border border-border p-3">
                                <SwitchField
                                    checked={data.add_to_menu}
                                    onCheckedChange={(v) => setData('add_to_menu', v)}
                                    label="Show in header menu"
                                    description="Adds this page to the site navigation when you publish."
                                />
                            </div>
                            {isEdit && published && data.slug && (
                                <a href={`${baseUrl}/${data.slug}`} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 text-sm font-medium underline underline-offset-2">
                                    <ExternalLink className="size-3.5" /> View live page
                                </a>
                            )}
                        </SettingsCard>

                        <SeoFields
                            seo={data.seo}
                            onChange={(patch) => setData('seo', { ...data.seo, ...patch })}
                            slug={data.slug}
                            onSlug={(v) => setData('slug', v)}
                            fallbackTitle={data.title}
                            baseUrl={baseUrl}
                        />
                        {errors.slug && <p className="text-xs text-destructive">{errors.slug}</p>}
                    </>
                }
            >
                <div className="grid gap-4">
                    <div>
                        <input
                            aria-label="Page title"
                            className="w-full border-0 bg-transparent px-1 text-3xl font-bold tracking-tight outline-none placeholder:text-muted-foreground/50"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="Add a page title…"
                        />
                        {errors.title && <p className="mt-1 px-1 text-xs text-destructive">{errors.title}</p>}
                    </div>

                    {built ? (
                        // Built with the Drop Builder — show the page big in the centre; continue from there.
                        <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border p-3">
                                <span className="flex items-center gap-2 text-sm font-medium"><LayoutTemplate className="size-4" /> Built with Drop Builder{page?.builder_edited_at ? ` · ${page.builder_edited_at}` : ''}</span>
                                <Button size="sm" onClick={openBuilder}><LayoutTemplate className="size-4" /> Continue in Drop Builder</Button>
                            </div>
                            {/* Live preview using the exact same widgets visitors see. */}
                            <div className="pointer-events-none max-h-[70vh] overflow-hidden bg-background">
                                <Suspense fallback={<div className="flex h-40 items-center justify-center"><Loader2 className="size-5 animate-spin text-muted-foreground" /></div>}>
                                    <PagePreview data={page!.puck!} posts={page!.posts ?? []} />
                                </Suspense>
                            </div>
                        </div>
                    ) : (
                        // Not built yet — big entry point into the builder.
                        <div className="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border bg-card p-16 text-center shadow-sm">
                            <span className="flex size-14 items-center justify-center rounded-2xl bg-foreground text-background"><LayoutTemplate className="size-7" /></span>
                            <h2 className="text-xl font-bold tracking-tight">Design your page</h2>
                            <p className="max-w-md text-sm text-muted-foreground">Open the Drop Builder for full drag-and-drop editing — blocks, columns, backgrounds, borders and a live desktop / tablet / mobile preview.</p>
                            <Button size="lg" className="mt-2" onClick={openBuilder}>
                                <LayoutTemplate className="size-4" /> {isEdit ? 'Open Drop Builder' : 'Save & open Drop Builder'}
                            </Button>
                        </div>
                    )}
                </div>
            </EditorShell>
        </>
    );
}
