import { Head, router, usePage } from '@inertiajs/react';
import { Archive, Check, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface Tab { key: string; label: string; count: number }
interface Item { id: number; label: string; sublabel: string | null; deleted_at: string | null }
interface Props { type: string; tabs: Tab[]; items: Item[] }

/** A small themed checkbox (select semantics — distinct from the on/off Switch). */
function Box({ checked, onChange, label }: { checked: boolean; onChange: () => void; label: string }) {
    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={checked}
            aria-label={label}
            onClick={onChange}
            className={cn('flex size-5 shrink-0 items-center justify-center rounded border transition-colors', checked ? 'border-foreground bg-foreground text-background' : 'border-input hover:border-foreground/40')}
        >
            {checked && <Check className="size-3.5" />}
        </button>
    );
}

export default function ArchiveIndex({ type, tabs, items }: Props) {
    const confirm = useConfirm();
    const flash = usePage().props.flash as { success?: string; error?: string } | undefined;
    const [sel, setSel] = useState<Set<number>>(new Set());

    const allSelected = items.length > 0 && sel.size === items.length;
    const toggle = (id: number) => setSel((s) => {
        const n = new Set(s);

        if (n.has(id)) {
            n.delete(id);
        } else {
            n.add(id);
        }

        return n;
    });
    const toggleAll = () => setSel(allSelected ? new Set() : new Set(items.map((i) => i.id)));

    const goTab = (key: string) => {
        setSel(new Set());
        router.get('/admin/archive', { type: key }, { preserveScroll: true, preserveState: false });
    };

    const restore = (ids: number[]) => router.post(`/admin/archive/${type}/restore`, { ids }, { preserveScroll: true, onSuccess: () => setSel(new Set()) });
    const purge = async (ids: number[], what: string) => {
        if (await confirm({ title: `Permanently delete ${what}?`, description: 'This cannot be undone — the data will be gone for good.', confirmText: 'Delete forever', destructive: true })) {
            router.post(`/admin/archive/${type}/delete`, { ids }, { preserveScroll: true, onSuccess: () => setSel(new Set()) });
        }
    };

    const selectedIds = [...sel];
    const activeLabel = tabs.find((t) => t.key === type)?.label ?? 'Items';

    return (
        <>
            <Head title="Archive" />
            <div className="mx-auto w-full max-w-4xl flex-1 p-4">
                <div className="mb-6 flex items-center gap-2">
                    <Archive className="size-5" />
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Archive</h1>
                        <p className="text-sm text-muted-foreground">Deleted items are kept here — restore them, or delete them permanently.</p>
                    </div>
                </div>

                {flash?.success && <div className="mb-4 rounded-lg bg-secondary px-4 py-2 text-sm">{flash.success}</div>}
                {flash?.error && <div className="mb-4 rounded-lg bg-destructive/10 px-4 py-2 text-sm text-destructive">{flash.error}</div>}

                {/* Tabs */}
                <div className="mb-5 flex flex-wrap gap-1.5">
                    {tabs.map((t) => {
                        const active = t.key === type;

                        return (
                            <button
                                key={t.key}
                                type="button"
                                onClick={() => goTab(t.key)}
                                className={cn('flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors', active ? 'border-foreground bg-foreground text-background' : 'border-border text-muted-foreground hover:text-foreground')}
                            >
                                {t.label}
                                <span className={cn('rounded-full px-1.5 text-xs', active ? 'bg-background/20' : 'bg-muted')}>{t.count}</span>
                            </button>
                        );
                    })}
                </div>

                {/* Bulk action bar */}
                {selectedIds.length > 0 && (
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-3 shadow-sm">
                        <span className="text-sm font-medium">{selectedIds.length} selected</span>
                        <div className="flex gap-2">
                            <Button size="sm" variant="outline" onClick={() => restore(selectedIds)}><RotateCcw className="size-3.5" /> Restore</Button>
                            <Button size="sm" variant="destructive" onClick={() => purge(selectedIds, `${selectedIds.length} item(s)`)}><Trash2 className="size-3.5" /> Delete permanently</Button>
                        </div>
                    </div>
                )}

                {items.length === 0 ? (
                    <p className="rounded-2xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">Nothing deleted in {activeLabel.toLowerCase()}.</p>
                ) : (
                    <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                        {/* Header / select-all */}
                        <div className="flex items-center gap-3 border-b border-border bg-muted/40 px-4 py-2.5">
                            <Box checked={allSelected} onChange={toggleAll} label="Select all" />
                            <span className="text-xs font-medium text-muted-foreground">{allSelected ? 'Deselect all' : 'Select all'}</span>
                        </div>
                        {items.map((it) => (
                            <div key={it.id} className="flex items-center gap-3 border-b border-border px-4 py-3 last:border-0">
                                <Box checked={sel.has(it.id)} onChange={() => toggle(it.id)} label={`Select ${it.label}`} />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium">{it.label}</div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {it.sublabel && <span>{it.sublabel} · </span>}deleted {it.deleted_at ?? 'recently'}
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <Button size="sm" variant="ghost" onClick={() => restore([it.id])} aria-label="Restore"><RotateCcw className="size-4" /></Button>
                                    <Button size="sm" variant="ghost" onClick={() => purge([it.id], `“${it.label}”`)} aria-label="Delete permanently"><Trash2 className="size-4 text-destructive" /></Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

ArchiveIndex.layout = { breadcrumbs: [{ title: 'Archive', href: '/admin/archive' }] };
