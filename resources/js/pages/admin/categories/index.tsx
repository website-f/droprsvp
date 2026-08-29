import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Shapes, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

interface Category { id: number; name: string; slug: string; sort_order: number; events_count: number }
interface Props { categories: Category[] }

const input = 'h-10 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

function Row({ category }: { category: Category }) {
    const confirm = useConfirm();
    const [name, setName] = useState(category.name);
    const [slug, setSlug] = useState(category.slug);
    const dirty = name !== category.name || slug !== category.slug;

    const save = () => router.put(`/admin/categories/${category.id}`, { name, slug }, { preserveScroll: true });
    const remove = async () => {
        if (await confirm({ title: `Delete “${category.name}”?`, description: `${category.events_count} event(s) will keep working but lose this category.`, confirmText: 'Delete', destructive: true })) {
            router.delete(`/admin/categories/${category.id}`, { preserveScroll: true });
        }
    };

    return (
        <div className="grid items-end gap-3 rounded-xl border border-border bg-card p-3 sm:grid-cols-[1fr_1fr_auto_auto]">
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
    );
}

export default function CategoriesIndex({ categories }: Props) {
    const add = useForm({ name: '' });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        add.post('/admin/categories', { preserveScroll: true, onSuccess: () => add.reset() });
    };

    return (
        <>
            <Head title="Categories" />
            <div className="mx-auto w-full max-w-3xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-2">
                    <Shapes className="size-5" />
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Event categories</h1>
                        <p className="text-sm text-muted-foreground">Used across discovery filters and event creation. Deleting one keeps its events (they just lose the category).</p>
                    </div>
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
                        {categories.map((c) => <Row key={c.id} category={c} />)}
                    </div>
                )}
            </div>
        </>
    );
}

CategoriesIndex.layout = { breadcrumbs: [{ title: 'Categories', href: '/admin/categories' }] };
