import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useConfirm } from '@/components/confirm-dialog';
import { ArrowDown, ArrowUp, ExternalLink, GripVertical, Pencil, Plus, Trash2, X } from 'lucide-react';

interface Item { id: number; label: string; url: string; new_tab: boolean; sort: number }
interface Quick { label: string; url: string }

const field = 'h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export default function MenuManager({ items, pages, builtins }: { items: Item[]; pages: Quick[]; builtins: Quick[] }) {
    const add = useForm({ label: '', url: '', new_tab: false });
    const [editingId, setEditingId] = useState<number | null>(null);
    const confirm = useConfirm();

    const quickAdd = (q: Quick) => {
        add.setData({ label: q.label, url: q.url, new_tab: false });
    };

    const submitAdd = (e: React.FormEvent) => {
        e.preventDefault();
        add.post('/admin/cms/menu', { preserveScroll: true, onSuccess: () => add.reset() });
    };

    const move = (index: number, dir: -1 | 1) => {
        const next = index + dir;
        if (next < 0 || next >= items.length) return;
        const ids = items.map((i) => i.id);
        [ids[index], ids[next]] = [ids[next], ids[index]];
        router.post('/admin/cms/menu/reorder', { ids }, { preserveScroll: true });
    };

    const remove = async (id: number) => {
        if (!await confirm({ title: 'Remove this menu item?', confirmText: 'Remove', destructive: true })) return;
        router.delete(`/admin/cms/menu/${id}`, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Menu" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-2">
                    <h1 className="text-2xl font-bold tracking-tight">Navigation menu</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        These links appear in the header and footer across the public site. Add your pages here so visitors can find them.
                    </p>
                </div>

                <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
                    {/* Current menu */}
                    <section className="rounded-xl border border-border bg-card p-4 shadow-sm">
                        <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Menu items</h2>
                        {items.length === 0 ? (
                            <p className="rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                                No menu items yet. Add one from the right.
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {items.map((item, i) => (
                                    <li key={item.id} className="rounded-lg border border-border bg-background p-2.5">
                                        {editingId === item.id ? (
                                            <EditRow item={item} onDone={() => setEditingId(null)} />
                                        ) : (
                                            <div className="flex items-center gap-2">
                                                <GripVertical className="size-4 shrink-0 text-muted-foreground" />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">{item.label}</p>
                                                    <p className="flex items-center gap-1 truncate text-xs text-muted-foreground">
                                                        {item.url}{item.new_tab && <ExternalLink className="size-3" />}
                                                    </p>
                                                </div>
                                                <div className="flex shrink-0 items-center">
                                                    <button type="button" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)} className="flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent disabled:opacity-30"><ArrowUp className="size-4" /></button>
                                                    <button type="button" aria-label="Move down" disabled={i === items.length - 1} onClick={() => move(i, 1)} className="flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent disabled:opacity-30"><ArrowDown className="size-4" /></button>
                                                    <button type="button" aria-label="Edit" onClick={() => setEditingId(item.id)} className="flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent"><Pencil className="size-4" /></button>
                                                    <button type="button" aria-label="Remove" onClick={() => remove(item.id)} className="flex size-8 items-center justify-center rounded-md text-destructive transition-colors hover:bg-destructive/10"><Trash2 className="size-4" /></button>
                                                </div>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {/* Add */}
                    <section className="grid content-start gap-4">
                        <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
                            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Add a link</h2>
                            <form onSubmit={submitAdd} className="grid gap-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="label">Label</Label>
                                    <input id="label" className={field} value={add.data.label} onChange={(e) => add.setData('label', e.target.value)} placeholder="e.g. About us" />
                                    {add.errors.label && <p className="text-xs text-destructive">{add.errors.label}</p>}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="url">Link</Label>
                                    <input id="url" className={field} value={add.data.url} onChange={(e) => add.setData('url', e.target.value)} placeholder="/about or https://…" />
                                    {add.errors.url && <p className="text-xs text-destructive">{add.errors.url}</p>}
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" className="size-4 rounded border-input" checked={add.data.new_tab} onChange={(e) => add.setData('new_tab', e.target.checked)} />
                                    Open in a new tab
                                </label>
                                <Button type="submit" disabled={add.processing}><Plus className="size-4" /> Add to menu</Button>
                            </form>
                        </div>

                        {(pages.length > 0 || builtins.length > 0) && (
                            <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
                                <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Quick add</h2>
                                <div className="flex flex-wrap gap-2">
                                    {builtins.map((q) => (
                                        <button key={q.url} type="button" onClick={() => quickAdd(q)} className="rounded-full border border-border px-3 py-1 text-xs font-medium transition-colors hover:border-foreground/40">{q.label}</button>
                                    ))}
                                </div>
                                {pages.length > 0 && (
                                    <>
                                        <p className="mb-2 mt-4 text-xs font-medium text-muted-foreground">Your pages</p>
                                        <div className="flex flex-wrap gap-2">
                                            {pages.map((q) => (
                                                <button key={q.url} type="button" onClick={() => quickAdd(q)} className="rounded-full border border-border px-3 py-1 text-xs font-medium transition-colors hover:border-foreground/40">{q.label}</button>
                                            ))}
                                        </div>
                                    </>
                                )}
                                <p className="mt-3 text-xs text-muted-foreground">Tap to fill the form, then adjust the label and press “Add to menu”.</p>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

function EditRow({ item, onDone }: { item: Item; onDone: () => void }) {
    const form = useForm({ label: item.label, url: item.url, new_tab: item.new_tab });
    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/admin/cms/menu/${item.id}`, { preserveScroll: true, onSuccess: onDone });
    };
    return (
        <form onSubmit={save} className="grid gap-2">
            <input className={field} value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="Label" />
            <input className={field} value={form.data.url} onChange={(e) => form.setData('url', e.target.value)} placeholder="/about or https://…" />
            <div className="flex items-center justify-between">
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" className="size-4 rounded border-input" checked={form.data.new_tab} onChange={(e) => form.setData('new_tab', e.target.checked)} />
                    New tab
                </label>
                <div className="flex gap-2">
                    <Button type="button" variant="ghost" size="sm" onClick={onDone}><X className="size-4" /> Cancel</Button>
                    <Button type="submit" size="sm" disabled={form.processing}>Save</Button>
                </div>
            </div>
        </form>
    );
}

MenuManager.layout = {
    breadcrumbs: [{ title: 'Menu', href: '/admin/cms/menu' }],
};
