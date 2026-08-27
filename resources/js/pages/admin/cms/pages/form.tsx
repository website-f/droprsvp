import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RichEditor } from '@/components/rich-editor';
import { SeoFields, type SeoData } from '@/components/seo-fields';
import { ArrowLeft } from 'lucide-react';

interface PageProp { id: number; title: string; slug: string; body: string | null; status: string; seo: SeoData }

const emptySeo = (): SeoData => ({ seo_title: null, meta_description: null, focus_keyphrase: null, canonical_url: null, robots_index: true, robots_follow: true, og_title: null, og_description: null, og_image: null });
const field = 'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

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

    const save = (publish: boolean) => {
        form.transform((d) => ({ ...d, publish }));
        isEdit ? form.put(`/admin/cms/pages/${page!.id}`) : form.post('/admin/cms/pages');
    };

    return (
        <>
            <Head title={isEdit ? 'Edit page' : 'New page'} />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/admin/cms/pages"><ArrowLeft className="size-4" /></Link></Button>
                    <h1 className="text-2xl font-bold tracking-tight">{isEdit ? 'Edit page' : 'New page'}</h1>
                </div>

                <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                    <div className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="title">Title</Label>
                            <input id="title" className={field} value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="Page title" />
                            {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Content</Label>
                            <RichEditor value={data.body} onChange={(html) => setData('body', html)} />
                        </div>
                    </div>

                    <div className="grid content-start gap-4">
                        <SeoFields
                            seo={data.seo}
                            onChange={(patch) => setData('seo', { ...data.seo, ...patch })}
                            slug={data.slug}
                            onSlug={(v) => setData('slug', v)}
                            fallbackTitle={data.title}
                            baseUrl={baseUrl}
                        />
                        {errors.slug && <p className="text-xs text-destructive">{errors.slug}</p>}
                        <div className="flex gap-3">
                            <Button variant="outline" className="flex-1" disabled={processing} onClick={() => save(false)}>Save draft</Button>
                            <Button className="flex-1" disabled={processing} onClick={() => save(true)}>Publish</Button>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

PageForm.layout = {
    breadcrumbs: [
        { title: 'Pages', href: '/admin/cms/pages' },
        { title: 'Editor', href: '#' },
    ],
};
