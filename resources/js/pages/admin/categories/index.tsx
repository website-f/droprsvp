import { Head, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Save, Search, Shapes, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface Category { id: number; name: string; slug: string; sort_order: number; events_count: number; content: string | null }
interface Props { categories: Category[]; browseSeo: { title: string; description: string } }

const input = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';
const area = 'w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

function Row({ category, first, last, onMove }: { category: Category; first: boolean; last: boolean; onMove: (dir: -1 | 1) => void }) {
    const confirm = useConfirm();
    const [name, setName] = useState(category.name);
    const [slug, setSlug] = useState(category.slug);
    const [content, setContent] = useState(category.content ?? '');
    const dirty = name !== category.name || slug !== category.slug || content !== (category.content ?? '');

    const save = () => router.put(`/admin/categories/${category.id}`, { name, slug, content }, { preserveScroll: true });
    const remove = async () => {
        if (await confirm({ title: `Delete “${category.name}”?`, description: `${category.events_count} event(s) will keep working but lose this category.`, confirmText: 'Delete', destructive: true })) {
            router.delete(`/admin/categories/${category.id}`, { preserveScroll: true });
        }
    };

    return (
        <div className="rounded-xl border border-border bg-card p-3">
            <div className="grid items-end gap-3 sm:grid-cols-[auto_1fr_1fr_auto_auto]">
                <div className="flex flex-col pb-0.5">
                    <button type="button" aria-label="Move up" disabled={first} onClick={() => onMove(-1)} className="flex size-6 items-center justify-center rounded text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowUp className="size-3.5" /></button>
                    <button type="button" aria-label="Move down" disabled={last} onClick={() => onMove(1)} className="flex size-6 items-center justify-center rounded text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowDown className="size-3.5" /></button>
                </div>
                <div className="grid gap-1.5">
                    <Label className="text-xs">Name</Label>
                    <input className={input} value={name} onChange={(e) => setName(e.target.value)} />
                </div>
                <div className="grid gap-1.5">
                    <Label className="text-xs">Slug</Label>
                    <input className={input} value={slug} onChange={(e) => setSlug(e.target.value)} />
                </div>
                <span className="pb-2.5 text-xs text-muted-foreground whitespace-nowrap">{category.events_count} event{category.events_count === 1 ? '' : 's'}</span>
                <div className="flex items-center gap-2 pb-0.5">
                    <Button size="sm" variant={dirty ? 'default' : 'outline'} onClick={save} disabled={!dirty || !name.trim()}>Save</Button>
                    <Button size="sm" variant="ghost" onClick={remove} aria-label="Delete"><Trash2 className="size-4" /></Button>
                </div>
            </div>
            <div className="mt-3 grid gap-1.5">
                <Label className="text-xs">Content <span className="font-normal text-muted-foreground">— shown (with “See more”) at the bottom of the browse page for this category</span></Label>
                <textarea rows={3} className={area} value={content} onChange={(e) => setContent(e.target.value)} placeholder="e.g. Discover the best live music events in Malaysia — from intimate gigs to festival nights…" />
            </div>
        </div>
    );
}

export default function CategoriesIndex({ categories, browseSeo }: Props) {
    const add = useForm({ name: '' });
    const seo = useForm({ title: browseSeo.title ?? '', description: browseSeo.description ?? '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        add.post('/admin/categories', { preserveScroll: true, onSuccess: () => add.reset() });
    };
    const saveSeo = () => seo.post('/admin/categories/browse-seo', { preserveScroll: true });
    const move = (index: number, dir: -1 | 1) => {
        const next = index + dir;

        if (next < 0 || next >= categories.length) {
            return;
        }

        const ids = categories.map((c) => c.id);
        [ids[index], ids[next]] = [ids[next], ids[index]];
        router.post('/admin/categories/reorder', { ids }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Categories" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-2">
                    <Shapes className="size-5" />
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Categories &amp; browse page</h1>
                        <p className="text-sm text-muted-foreground">Manage the categories used across discovery, plus the SEO of the browse page.</p>
                    </div>
                </div>

                {/* Browse page SEO */}
                <div className="mb-6 grid gap-3 rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div className="flex items-center gap-2 text-sm font-semibold"><Search className="size-4" /> Browse page SEO <span className="font-normal text-muted-foreground">(/en-my/all)</span></div>
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Title</Label>
                        <input className={input} value={seo.data.title} onChange={(e) => seo.setData('title', e.target.value)} placeholder="Browse events" />
                    </div>
                    <div className="grid gap-1.5">
                        <Label className="text-xs">Meta description</Label>
                        <textarea rows={2} className={area} value={seo.data.description} onChange={(e) => seo.setData('description', e.target.value)} placeholder="Discover events near you and get tickets on DropRSVP." />
                    </div>
                    <div><Button size="sm" onClick={saveSeo} disabled={seo.processing}><Save className="size-3.5" /> Save SEO</Button></div>
                </div>

                <form onSubmit={submit} className="mb-6 flex gap-2">
                    <input className={input} value={add.data.name} onChange={(e) => add.setData('name', e.target.value)} placeholder="New category name…" />
                    <Button type="submit" disabled={add.processing || !add.data.name.trim()}><Plus className="size-4" /> Add</Button>
                </form>
                {add.errors.name && <p className="-mt-4 mb-4 text-xs text-destructive">{add.errors.name}</p>}

                {categories.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">No categories yet.</p>
                ) : (
                    <div className="grid gap-3">
                        {categories.map((c, i) => <Row key={c.id} category={c} first={i === 0} last={i === categories.length - 1} onMove={(dir) => move(i, dir)} />)}
                    </div>
                )}
            </div>
        </>
    );
}

CategoriesIndex.layout = { breadcrumbs: [{ title: 'Categories', href: '/admin/categories' }] };
