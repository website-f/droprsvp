import { Head, useForm } from '@inertiajs/react';
import { CalendarClock, ExternalLink, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { EditorShell, SettingsCard } from '@/components/cms/editor-shell';
import { RichEditor } from '@/components/rich-editor';
import { SeoFields  } from '@/components/seo-fields';
import type {SeoData} from '@/components/seo-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CreatableSelect } from '@/components/ui/creatable-select';
import { DateTimePicker } from '@/components/ui/date-time-picker';
import { Label } from '@/components/ui/label';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { uploadImageWithToast } from '@/lib/upload';

interface PostProp { id: number; title: string; slug: string; excerpt: string | null; body: string | null; cover_image: string | null; category: string | null; status: string; published_at: string | null; seo: SeoData }
type Status = 'draft' | 'published' | 'scheduled';

const emptySeo = (): SeoData => ({ seo_title: null, meta_description: null, focus_keyphrase: null, meta_keywords: null, canonical_url: null, robots_index: true, robots_follow: true, og_title: null, og_description: null, og_image: null });
const area = 'w-full rounded-lg border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/** "YYYY-MM-DDTHH:mm" for a moment `minutes` from now, in the browser's zone. */
const inMinutes = (minutes: number) => {
    const d = new Date(Date.now() + minutes * 60_000);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const STATUS_META: Record<Status, { label: string; tone: 'default' | 'secondary' | 'outline' }> = {
    published: { label: 'Published', tone: 'default' },
    scheduled: { label: 'Scheduled', tone: 'outline' },
    draft: { label: 'Draft', tone: 'secondary' },
};

export default function PostForm({ post, categories }: { post: PostProp | null; categories: string[] }) {
    const isEdit = !!post;
    const [baseUrl] = useState(() => (typeof window !== 'undefined' ? window.location.origin : ''));

    const form = useForm({
        title: post?.title ?? '',
        slug: post?.slug ?? '',
        excerpt: post?.excerpt ?? '',
        body: post?.body ?? '',
        cover_image: post?.cover_image ?? '',
        category: post?.category ?? '',
        status: (post?.status as Status) ?? 'draft',
        published_at: post?.published_at ?? '',
        seo: post?.seo ?? emptySeo(),
    });
    const { data, setData, processing, errors } = form;
    useUnsavedChanges(form.isDirty && !processing);
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const published = post?.status === 'published';
    // A future date turns "Publish" into "Schedule" — the same affordance
    // WordPress uses, rather than a separate mode to remember. The clock is
    // sampled once on mount (reading it during render isn't pure); the actual
    // decision is re-made against the real time on submit, and again server-side.
    const [openedAt] = useState(() => Date.now());
    const isFuture = !!data.published_at && new Date(data.published_at).getTime() > openedAt;
    const primaryLabel = isFuture ? (post?.status === 'scheduled' ? 'Update schedule' : 'Schedule') : (published ? 'Update' : 'Publish');

    const onPickCover = async (file: File | undefined) => {
        if (!file) {
return;
}

        setUploading(true);
        const url = await uploadImageWithToast(file);

        if (url) {
            setData('cover_image', url);
        }

        setUploading(false);
    };

    const save = (status: Status) => {
        // A future date always means "scheduled", whichever button was pressed —
        // checked against the clock now, not when the page was opened.
        const future = !!data.published_at && new Date(data.published_at).getTime() > Date.now();
        const resolved: Status = status === 'draft' ? 'draft' : (future ? 'scheduled' : 'published');
        form.transform((d) => ({ ...d, status: resolved }));

        if (isEdit) {
            form.put(`/admin/cms/posts/${post!.id}`);
        } else {
            form.post('/admin/cms/posts');
        }
    };

    return (
        <>
            <Head title={isEdit ? 'Edit post' : 'New post'} />
            <EditorShell
                backHref="/admin/cms/posts"
                backLabel="Posts"
                title={data.title || (isEdit ? 'Edit post' : 'New post')}
                status={<Badge variant={STATUS_META[(post?.status as Status) ?? 'draft'].tone}>{STATUS_META[(post?.status as Status) ?? 'draft'].label}</Badge>}
                actions={
                    <>
                        <Button variant="outline" size="sm" disabled={processing} onClick={() => save('draft')}>Save draft</Button>
                        <Button size="sm" disabled={processing} onClick={() => save('published')}>{primaryLabel}</Button>
                    </>
                }
                sidebar={
                    <>
                        {/* Publish — WordPress's "Publish immediately / edit" box. */}
                        <SettingsCard title="Publish">
                            {post?.status === 'scheduled' && post.published_at && (
                                <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/40 p-3 text-xs">
                                    <CalendarClock className="mt-0.5 size-4 shrink-0" />
                                    <span>This post goes live automatically on the date below. Nothing is public until then.</span>
                                </div>
                            )}
                            <div className="grid gap-1.5">
                                <Label htmlFor="published_at">Publish date</Label>
                                <DateTimePicker id="published_at" value={data.published_at} onChange={(v) => setData('published_at', v)} placeholder="Publish immediately" />
                                <p className="text-xs text-muted-foreground">
                                    {isFuture
                                        ? 'A future date schedules the post — it publishes itself at that moment.'
                                        : 'Leave empty to publish the moment you press Publish.'}
                                </p>
                                {errors.published_at && <p className="text-xs text-destructive">{errors.published_at}</p>}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" size="sm" variant="outline" onClick={() => setData('published_at', inMinutes(60))}>In 1 hour</Button>
                                <Button type="button" size="sm" variant="outline" onClick={() => setData('published_at', inMinutes(60 * 24))}>Tomorrow</Button>
                                {data.published_at && (
                                    <Button type="button" size="sm" variant="ghost" className="text-muted-foreground" onClick={() => setData('published_at', '')}>Clear</Button>
                                )}
                            </div>
                        </SettingsCard>

                        <SettingsCard title="Post">
                            <div className="grid gap-1.5">
                                <Label htmlFor="category">Category</Label>
                                <CreatableSelect id="category" value={data.category} onChange={(v) => setData('category', v)} options={categories} placeholder="Choose or create a category…" />
                                <p className="text-xs text-muted-foreground">Pick an existing one or type a new name to create it.</p>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="excerpt">Excerpt</Label>
                                <textarea id="excerpt" rows={3} className={area} value={data.excerpt} onChange={(e) => setData('excerpt', e.target.value)} placeholder="Short summary shown in listings" />
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Featured image</Label>
                                {data.cover_image
                                    ? (
                                        <div className="relative overflow-hidden rounded-lg border border-border">
                                            <img src={data.cover_image} alt="" className="aspect-[16/9] w-full object-cover" />
                                            <div className="absolute right-2 top-2 flex gap-2">
                                                <Button type="button" size="sm" variant="secondary" disabled={uploading} onClick={() => fileRef.current?.click()}>Replace</Button>
                                                <Button type="button" size="sm" variant="secondary" onClick={() => setData('cover_image', '')}>Remove</Button>
                                            </div>
                                        </div>
                                    )
                                    : (
                                        <button type="button" onClick={() => fileRef.current?.click()} disabled={uploading}
                                            className="flex aspect-[16/9] w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border text-sm text-muted-foreground transition-colors hover:border-foreground/40 hover:text-foreground">
                                            <Upload className="size-5" />
                                            {uploading ? 'Uploading…' : 'Upload featured image'}
                                        </button>
                                    )}
                                <input ref={fileRef} type="file" accept="image/*" hidden onChange={(e) => onPickCover(e.target.files?.[0])} />
                            </div>
                            {isEdit && published && data.slug && (
                                <a href={`${baseUrl}/en-my/blog/${data.slug}/`} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 text-sm font-medium underline underline-offset-2">
                                    <ExternalLink className="size-3.5" /> View post
                                </a>
                            )}
                        </SettingsCard>

                        <SeoFields
                            seo={data.seo}
                            onChange={(patch) => setData('seo', { ...data.seo, ...patch })}
                            slug={data.slug}
                            onSlug={(v) => setData('slug', v)}
                            fallbackTitle={data.title}
                            baseUrl={`${baseUrl}/en-my/blog`}
                        />
                        {errors.slug && <p className="text-xs text-destructive">{errors.slug}</p>}
                    </>
                }
            >
                <div className="grid gap-4">
                    <div>
                        <input
                            aria-label="Post title"
                            className="w-full border-0 bg-transparent px-1 text-3xl font-bold tracking-tight outline-none placeholder:text-muted-foreground/50"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="Add a post title…"
                        />
                        {errors.title && <p className="mt-1 px-1 text-xs text-destructive">{errors.title}</p>}
                    </div>
                    <RichEditor value={data.body} onChange={(html) => setData('body', html)} placeholder="Start writing, or use the toolbar to add headings, images and section dividers…" />
                </div>
            </EditorShell>
        </>
    );
}
