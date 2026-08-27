import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { SeoFields, type SeoData } from '@/components/seo-fields';
import { EditorShell, SettingsCard } from '@/components/cms/editor-shell';
import { PageBuilder, normalizeSections, sectionsToHtml } from '@/components/cms/page-builder';
import { PageSections, type PageSection } from '@/components/cms/page-sections';
import { Eye, ExternalLink, Pencil } from 'lucide-react';

interface PageProp { id: number; title: string; slug: string; body: string | null; layout: PageSection[] | null; status: string; in_menu: boolean; seo: SeoData }

const emptySeo = (): SeoData => ({ seo_title: null, meta_description: null, focus_keyphrase: null, canonical_url: null, robots_index: true, robots_follow: true, og_title: null, og_description: null, og_image: null });

/** Start from the saved layout; migrate a legacy body into one text section. */
function initialSections(page: PageProp | null): PageSection[] {
    const norm = normalizeSections(page?.layout);
    if (norm.length > 0) return norm;
    if (page?.body) return [{ id: 's_legacy', title: '', columns: [{ blocks: [{ id: 'b_legacy', type: 'richtext', html: page.body }] }] }];
    return [];
}

export default function PageForm({ page }: { page: PageProp | null }) {
    const isEdit = !!page;
    const [baseUrl, setBaseUrl] = useState('');
    const [mode, setMode] = useState<'edit' | 'preview'>('edit');
    useEffect(() => setBaseUrl(window.location.origin), []);

    const form = useForm({
        title: page?.title ?? '',
        slug: page?.slug ?? '',
        layout: initialSections(page),
        add_to_menu: page?.in_menu ?? false,
        publish: false,
        seo: page?.seo ?? emptySeo(),
    });
    const { data, setData, processing, errors } = form;
    const published = page?.status === 'published';

    const save = (publish: boolean) => {
        form.transform((d) => ({ ...d, publish, body: sectionsToHtml(d.layout) }));
        isEdit ? form.put(`/admin/cms/pages/${page!.id}`) : form.post('/admin/cms/pages');
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
                            <label className="flex items-start gap-2.5 rounded-lg border border-border p-3 text-sm">
                                <input type="checkbox" className="mt-0.5 size-4 rounded border-input" checked={data.add_to_menu} onChange={(e) => setData('add_to_menu', e.target.checked)} />
                                <span>
                                    <span className="font-medium">Show in header menu</span>
                                    <span className="mt-0.5 block text-xs text-muted-foreground">Adds this page to the site navigation when you publish, so visitors can find it from the landing page.</span>
                                </span>
                            </label>
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
                    <div className="flex items-start justify-between gap-3">
                        <input
                            aria-label="Page title"
                            className="min-w-0 flex-1 border-0 bg-transparent px-1 text-3xl font-bold tracking-tight outline-none placeholder:text-muted-foreground/50"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="Add a page title…"
                        />
                        <div className="flex shrink-0 items-center rounded-lg border border-border p-0.5">
                            <button type="button" onClick={() => setMode('edit')} className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ${mode === 'edit' ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-accent'}`}><Pencil className="size-3.5" /> Edit</button>
                            <button type="button" onClick={() => setMode('preview')} className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ${mode === 'preview' ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-accent'}`}><Eye className="size-3.5" /> Preview</button>
                        </div>
                    </div>
                    {errors.title && <p className="px-1 text-xs text-destructive">{errors.title}</p>}

                    {mode === 'edit' ? (
                        <PageBuilder value={data.layout} onChange={(s) => setData('layout', s)} />
                    ) : (
                        <div className="rounded-2xl border border-border bg-card p-6 sm:p-10">
                            <div className="mx-auto max-w-3xl">
                                <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{data.title || 'Untitled page'}</h1>
                                <div className="mt-8">
                                    {data.layout.length > 0
                                        ? <PageSections sections={data.layout} />
                                        : <p className="text-sm text-muted-foreground">Nothing to preview yet — add a section in Edit mode.</p>}
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </EditorShell>
        </>
    );
}
