import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RichEditor } from '@/components/rich-editor';
import { SeoFields, type SeoData } from '@/components/seo-fields';
import { uploadImage } from '@/lib/upload';
import { ArrowLeft, Upload } from 'lucide-react';

interface PostProp { id: number; title: string; slug: string; excerpt: string | null; body: string | null; cover_image: string | null; category: string | null; status: string; seo: SeoData }

const emptySeo = (): SeoData => ({ seo_title: null, meta_description: null, focus_keyphrase: null, canonical_url: null, robots_index: true, robots_follow: true, og_title: null, og_description: null, og_image: null });
const field = 'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function PostForm({ post, categories }: { post: PostProp | null; categories: string[] }) {
    const isEdit = !!post;
    const [baseUrl, setBaseUrl] = useState('');
    useEffect(() => setBaseUrl(window.location.origin ? `${window.location.origin}/blog` : ''), []);

    const form = useForm({
        title: post?.title ?? '',
        slug: post?.slug ?? '',
        excerpt: post?.excerpt ?? '',
        body: post?.body ?? '',
        cover_image: post?.cover_image ?? '',
        category: post?.category ?? '',
        publish: false,
        seo: post?.seo ?? emptySeo(),
    });
    const { data, setData, processing, errors } = form;
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const onPickCover = async (file: File | undefined) => {
        if (!file) return;
        setUploading(true);
        try {
            setData('cover_image', await uploadImage(file));
        } catch {
            /* surfaced via the empty field; keep it simple */
        } finally {
            setUploading(false);
        }
    };

    const save = (publish: boolean) => {
        form.transform((d) => ({ ...d, publish }));
        isEdit ? form.put(`/admin/cms/posts/${post!.id}`) : form.post('/admin/cms/posts');
    };

    return (
        <>
            <Head title={isEdit ? 'Edit post' : 'New post'} />
            <div className="mx-auto w-full max-w-5xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/admin/cms/posts"><ArrowLeft className="size-4" /></Link></Button>
                    <h1 className="text-2xl font-bold tracking-tight">{isEdit ? 'Edit post' : 'New post'}</h1>
                </div>

                <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                    <div className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="title">Title</Label>
                            <input id="title" className={field} value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="Post title" />
                            {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="excerpt">Excerpt</Label>
                            <textarea id="excerpt" rows={2} className={area} value={data.excerpt} onChange={(e) => setData('excerpt', e.target.value)} placeholder="Short summary shown in listings" />
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Content</Label>
                            <RichEditor value={data.body} onChange={(html) => setData('body', html)} />
                        </div>
                    </div>

                    <div className="grid content-start gap-4">
                        <div className="rounded-xl border border-border bg-card p-5 shadow-sm">
                            <div className="grid gap-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="category">Category</Label>
                                    <input id="category" list="cms-categories" className={field} value={data.category} onChange={(e) => setData('category', e.target.value)} placeholder="e.g. News" />
                                    <datalist id="cms-categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="cover">Cover image</Label>
                                    <div className="flex gap-2">
                                        <input id="cover" className={field} value={data.cover_image} onChange={(e) => setData('cover_image', e.target.value)} placeholder="https://… or upload" />
                                        <Button type="button" variant="outline" className="shrink-0" disabled={uploading} onClick={() => fileRef.current?.click()}>
                                            <Upload className="size-4" /> {uploading ? '…' : 'Upload'}
                                        </Button>
                                        <input ref={fileRef} type="file" accept="image/*" hidden onChange={(e) => onPickCover(e.target.files?.[0])} />
                                    </div>
                                    {data.cover_image && <img src={data.cover_image} alt="" className="mt-1 aspect-[16/9] w-full rounded-lg object-cover" />}
                                </div>
                            </div>
                        </div>

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

PostForm.layout = {
    breadcrumbs: [
        { title: 'Posts', href: '/admin/cms/posts' },
        { title: 'Editor', href: '#' },
    ],
};
