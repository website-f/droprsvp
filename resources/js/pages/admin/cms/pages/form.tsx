import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { RichEditor } from '@/components/rich-editor';
import { SeoFields, type SeoData } from '@/components/seo-fields';
import { EditorShell, SettingsCard } from '@/components/cms/editor-shell';
import { ExternalLink } from 'lucide-react';

interface PageProp { id: number; title: string; slug: string; body: string | null; status: string; seo: SeoData }

const emptySeo = (): SeoData => ({ seo_title: null, meta_description: null, focus_keyphrase: null, canonical_url: null, robots_index: true, robots_follow: true, og_title: null, og_description: null, og_image: null });

export default function PageForm({ page }: { page: PageProp | null }) {
    const isEdit = !!page;
    const [baseUrl, setBaseUrl] = useState('');
    useEffect(() => setBaseUrl(window.location.origin), []);

    const form = useForm({
        title: page?.title ?? '',
        slug: page?.slug ?? '',
        body: page?.body ?? '',
        publish: false,
        seo: page?.seo ?? emptySeo(),
    });
    const { data, setData, processing, errors } = form;
    const published = page?.status === 'published';

    const save = (publish: boolean) => {
        form.transform((d) => ({ ...d, publish }));
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
                            {isEdit && published && data.slug && (
                                <a href={`${baseUrl}/${data.slug}`} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 text-sm font-medium underline underline-offset-2">
                                    <ExternalLink className="size-3.5" /> View page
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
                    <RichEditor value={data.body} onChange={(html) => setData('body', html)} placeholder="Start writing, or use the toolbar to add headings, images and section dividers…" />
                </div>
            </EditorShell>
        </>
    );
}
