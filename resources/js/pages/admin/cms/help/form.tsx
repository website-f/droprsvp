import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { RegionPicker, RegionVersionTabs } from '@/components/admin/region-versions';
import type { RegionOption, VersionTab } from '@/components/admin/region-versions';
import { RichEditor } from '@/components/rich-editor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface ArticleProp {
    id: number; title: string; slug: string; category: string; excerpt: string | null; body: string | null; status: string; sort: number;
    parent_id?: number | null; address?: string | null; regions?: string[]; versions?: VersionTab[];
}

const field = 'h-11 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function HelpForm({ article, categories, regionOptions = [] }: { article: ArticleProp | null; categories: string[]; regionOptions?: RegionOption[] }) {
    const isEdit = !!article;
    const form = useForm({
        title: article?.title ?? '',
        slug: article?.slug ?? '',
        category: article?.category ?? '',
        excerpt: article?.excerpt ?? '',
        body: article?.body ?? '',
        sort: article?.sort ?? 0,
        publish: false,
        // Main article: where it shows (empty = all). Region version: who it is for.
        regions: article?.regions ?? [],
    });
    const { data, setData, processing, errors } = form;
    const isVersion = !!article?.parent_id;
    const regionError = (errors as Record<string, string | undefined>).regions;
    const published = article?.status === 'published';

    const save = (publish: boolean) => {
        form.transform((d) => ({ ...d, publish }));

        if (isEdit) {
            form.put(`/admin/cms/help/${article!.id}`);
        } else {
            form.post('/admin/cms/help');
        }
    };

    return (
        <>
            <Head title={isEdit ? 'Edit article' : 'New article'} />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-3">
                    <Button asChild variant="ghost" size="icon"><Link href="/admin/cms/help"><ArrowLeft className="size-4" /></Link></Button>
                    <h1 className="text-2xl font-bold tracking-tight">{isEdit ? 'Edit article' : 'New article'}</h1>
                    {isEdit && <Badge variant={published ? 'default' : 'secondary'}>{published ? 'Published' : 'Draft'}</Badge>}
                </div>

                <div className="grid gap-4">
                    {isEdit && article?.versions && (
                        <RegionVersionTabs
                            tabs={article.versions}
                            options={regionOptions}
                            editHref={(id) => `/admin/cms/help/${id}/edit`}
                            addUrl={`/admin/cms/help/${article.versions.find((t) => t.main)?.id ?? article.id}/versions`}
                        />
                    )}
                    <div className="grid gap-1.5">
                        <Label htmlFor="title">Title</Label>
                        <input id="title" className={field} value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="e.g. How to buy a ticket" />
                        {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="category">Category</Label>
                            <input id="category" list="help-cats" className={field} value={data.category} onChange={(e) => setData('category', e.target.value)} placeholder="e.g. Buying tickets" />
                            <datalist id="help-cats">{categories.map((c) => <option key={c} value={c} />)}</datalist>
                            {errors.category && <p className="text-xs text-destructive">{errors.category}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="sort">Sort order</Label>
                            <input id="sort" type="number" className={field} value={data.sort} onChange={(e) => setData('sort', Number(e.target.value))} />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="excerpt">Excerpt</Label>
                        <textarea id="excerpt" rows={2} className={area} value={data.excerpt} onChange={(e) => setData('excerpt', e.target.value)} placeholder="One-line summary shown in the list" />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Content</Label>
                        <RichEditor value={data.body} onChange={(html) => setData('body', html)} />
                    </div>
                    {regionOptions.length > 1 && (
                        <div className="grid gap-2 rounded-xl border border-border p-4">
                            <Label>{isVersion ? 'This version is for' : 'Shown in'}</Label>
                            <p className="-mt-1 text-xs text-muted-foreground">
                                {isVersion ? 'Visitors in these regions see this version; everyone else sees the main article.' : 'Where this article appears. Regions with their own version see that instead.'}
                            </p>
                            <RegionPicker
                                options={regionOptions}
                                value={data.regions}
                                onChange={(v) => setData('regions', v)}
                                allowAll={!isVersion}
                                taken={(article?.versions ?? []).filter((t) => !t.main && !t.active).flatMap((t) => t.regions)}
                            />
                            {regionError && <p className="text-xs text-destructive">{regionError}</p>}
                        </div>
                    )}
                    <div className="flex flex-wrap justify-end gap-3">
                        {isVersion && article && (
                            <Button variant="ghost" className="mr-auto text-destructive hover:bg-destructive/10 hover:text-destructive"
                                onClick={() => window.confirm('Delete this region version? Its regions will see the main article again.') && router.delete(`/admin/cms/help/${article.id}`)}>
                                Delete this version
                            </Button>
                        )}
                        <Button variant="outline" disabled={processing} onClick={() => save(false)}>Save draft</Button>
                        <Button disabled={processing} onClick={() => save(true)}>{published ? 'Update' : 'Publish'}</Button>
                    </div>
                </div>
            </div>
        </>
    );
}

HelpForm.layout = { breadcrumbs: [{ title: 'Help center', href: '/admin/cms/help' }, { title: 'Editor', href: '#' }] };
