import { Check, Copy, ImageUp, Loader2, Search, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useConfirm } from '@/components/confirm-dialog';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { imageError, uploadMedia, xsrfToken } from '@/lib/upload';
import type { MediaItem } from '@/lib/upload';
import { cn } from '@/lib/utils';

interface Page { data: MediaItem[]; next: number | null; total: number; months: Record<string, string> | null }

const input = 'h-9 w-full rounded-lg border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

export function formatBytes(n: number | null): string {
    if (!n) {
        return '—';
    }

    if (n < 1024 * 1024) {
        return `${Math.max(1, Math.round(n / 1024))} KB`;
    }

    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
    const res = await fetch(url, {
        ...init,
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), ...(init.body ? { 'Content-Type': 'application/json' } : {}), ...init.headers },
        credentials: 'same-origin',
    });

    if (!res.ok) {
        throw new Error(res.status === 403 ? 'You can only change images you uploaded.' : 'Something went wrong — please try again.');
    }

    return (await res.json()) as T;
}

/**
 * The Media Library: every image uploaded to DropRSVP, to upload to, search,
 * filter by month and reuse. Used full-page (Content → Media) and inside the
 * picker the page and email builders open.
 *
 * Admins see every image; anyone else sees what they uploaded (the server
 * decides — App\Models\Media::visibleTo).
 */
export function MediaBrowser({ onPick, pickLabel = 'Use this image', manage = false, className }: {
    /** Picker mode: called with the chosen image. */
    onPick?: (item: MediaItem) => void;
    pickLabel?: string;
    /** The full Media page: deleting is offered here rather than in a picker. */
    manage?: boolean;
    className?: string;
}) {
    const confirm = useConfirm();
    const [items, setItems] = useState<MediaItem[]>([]);
    const [next, setNext] = useState<number | null>(null);
    const [total, setTotal] = useState(0);
    const [months, setMonths] = useState<Record<string, string>>({});
    const [q, setQ] = useState('');
    const [month, setMonth] = useState('all');
    const [loading, setLoading] = useState(true);
    const [uploading, setUploading] = useState(0);
    const [dragging, setDragging] = useState(false);
    const [selected, setSelected] = useState<MediaItem | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const load = useCallback(async (page: number, replace: boolean) => {
        setLoading(true);

        try {
            const params = new URLSearchParams({ page: String(page) });

            if (q.trim()) {
                params.set('q', q.trim());
            }

            if (month !== 'all') {
                params.set('month', month);
            }

            const data = await request<Page>(`/media-library?${params}`);
            setItems((current) => (replace ? data.data : [...current, ...data.data]));
            setNext(data.next);
            setTotal(data.total);

            if (data.months) {
                setMonths(data.months);
            }
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setLoading(false);
        }
    }, [q, month]);

    // Searching waits for a pause in typing.
    useEffect(() => {
        const timer = window.setTimeout(() => load(1, true), q ? 300 : 0);

        return () => window.clearTimeout(timer);
    }, [load, q]);

    const upload = async (files: FileList | File[]) => {
        const list = Array.from(files);
        const problems = list.map(imageError).filter(Boolean) as string[];
        problems.forEach((problem) => toast.error(problem));

        const good = list.filter((file) => !imageError(file));
        setUploading((n) => n + good.length);

        const added: MediaItem[] = [];

        await Promise.all(good.map(async (file) => {
            try {
                added.push(await uploadMedia(file));
            } catch (error) {
                toast.error((error as Error).message);
            } finally {
                setUploading((n) => n - 1);
            }
        }));

        if (added.length > 0) {
            setItems((current) => [...added, ...current]);
            setTotal((t) => t + added.length);
            setSelected(added[0]);
            toast.success(added.length === 1 ? 'Image added to the library' : `${added.length} images added to the library`);
        }
    };

    const save = async (item: MediaItem, patch: { name?: string; alt?: string | null }) => {
        try {
            const { media } = await request<{ media: MediaItem }>(`/media-library/${item.id}`, { method: 'PATCH', body: JSON.stringify(patch) });
            setItems((current) => current.map((m) => (m.id === media.id ? media : m)));
            setSelected(media);
            toast.success('Saved');
        } catch (error) {
            toast.error((error as Error).message);
        }
    };

    const remove = async (item: MediaItem) => {
        const ok = await confirm({
            title: `Delete “${item.name}”?`,
            description: 'The file is removed for good. Any page, event or email still using it will show a missing image.',
            confirmText: 'Delete image',
            destructive: true,
        });

        if (!ok) {
            return;
        }

        try {
            await request(`/media-library/${item.id}`, { method: 'DELETE' });
            setItems((current) => current.filter((m) => m.id !== item.id));
            setTotal((t) => t - 1);
            setSelected(null);
            toast.success('Image deleted');
        } catch (error) {
            toast.error((error as Error).message);
        }
    };

    const copy = async (item: MediaItem) => {
        try {
            await navigator.clipboard.writeText(item.url);
            toast.success('Image URL copied');
        } catch {
            toast.error('Could not copy — select the URL and copy it by hand.');
        }
    };

    return (
        <div className={cn('flex min-h-0 flex-col gap-3', className)}>
            {/* Toolbar */}
            <div className="flex flex-wrap items-center gap-2">
                <Button type="button" size="sm" onClick={() => fileInput.current?.click()}>
                    <ImageUp className="size-4" /> Upload
                </Button>
                <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple className="sr-only"
                    onChange={(e) => {
                        if (e.target.files) {
                            upload(e.target.files);
                        }

                        e.target.value = '';
                    }} />
                <div className="w-44">
                    <AppSelect value={month} onChange={setMonth} aria-label="Filter by month"
                        options={[{ value: 'all', label: 'All dates' }, ...Object.entries(months).map(([value, label]) => ({ value, label }))]} />
                </div>
                <div className="relative min-w-48 flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search media" aria-label="Search media" className={`${input} pl-9`} />
                </div>
                <span className="text-xs text-muted-foreground">{total} image{total === 1 ? '' : 's'}</span>
            </div>

            <div className="grid min-h-0 flex-1 gap-4 lg:grid-cols-[minmax(0,1fr)_280px]">
                {/* The grid, which is also a drop target */}
                <div
                    className={cn('relative min-h-0 overflow-y-auto rounded-xl border border-border bg-card p-3', dragging && 'border-dashed border-foreground bg-muted/40')}
                    onDragOver={(e) => {
                        e.preventDefault();
                        setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={(e) => {
                        e.preventDefault();
                        setDragging(false);

                        if (e.dataTransfer.files.length > 0) {
                            upload(e.dataTransfer.files);
                        }
                    }}
                >
                    {dragging && (
                        <div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center text-sm font-medium">Drop images to upload</div>
                    )}

                    {items.length === 0 && uploading === 0 ? (
                        <div className="flex h-56 flex-col items-center justify-center gap-2 text-center text-sm text-muted-foreground">
                            {loading ? <Loader2 className="size-5 animate-spin" /> : (
                                <>
                                    <ImageUp className="size-6" />
                                    {q || month !== 'all' ? 'No images match.' : 'No images yet — upload some, or drop them here.'}
                                </>
                            )}
                        </div>
                    ) : (
                        <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5 xl:grid-cols-6">
                            {Array.from({ length: uploading }).map((_, i) => (
                                <li key={`up-${i}`} className="flex aspect-square items-center justify-center rounded-lg border border-dashed border-border bg-muted/40">
                                    <Loader2 className="size-5 animate-spin text-muted-foreground" />
                                </li>
                            ))}
                            {items.map((item) => {
                                const active = selected?.id === item.id;

                                return (
                                    <li key={item.id}>
                                        <button
                                            type="button"
                                            onClick={() => setSelected(item)}
                                            onDoubleClick={() => onPick?.(item)}
                                            aria-pressed={active}
                                            title={item.name}
                                            className={cn('group relative block w-full overflow-hidden rounded-lg border bg-muted/30 text-left transition-shadow', active ? 'border-foreground ring-2 ring-foreground' : 'border-border hover:border-foreground/40')}
                                        >
                                            <img src={item.thumb} alt={item.alt ?? ''} loading="lazy" className="aspect-square w-full object-contain"
                                                onError={(e) => {
                                                    if (e.currentTarget.src !== item.url) {
                                                        e.currentTarget.src = item.url;
                                                    }
                                                }} />
                                            <span className="block truncate border-t border-border bg-card px-1.5 py-1 text-[11px]">{item.name}</span>
                                            {active && <span className="absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full bg-foreground text-background"><Check className="size-3" /></span>}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {next && (
                        <div className="mt-3 flex justify-center">
                            <Button type="button" variant="outline" size="sm" disabled={loading} onClick={() => load(next, false)}>
                                {loading ? <Loader2 className="size-4 animate-spin" /> : 'Load more'}
                            </Button>
                        </div>
                    )}
                </div>

                {/* The chosen image */}
                <aside className="min-h-0 overflow-y-auto rounded-xl border border-border bg-card p-3">
                    {selected ? (
                        <Details
                            key={selected.id}
                            item={selected}
                            onPick={onPick}
                            pickLabel={pickLabel}
                            onCopy={copy}
                            onSave={save}
                            onDelete={manage && selected.editable ? remove : undefined}
                        />
                    ) : (
                        <p className="py-10 text-center text-sm text-muted-foreground">Select an image to see its details{onPick ? ' and use it' : ''}.</p>
                    )}
                </aside>
            </div>
        </div>
    );
}

function Details({ item, onPick, pickLabel, onCopy, onSave, onDelete }: {
    item: MediaItem;
    onPick?: (item: MediaItem) => void;
    pickLabel: string;
    onCopy: (item: MediaItem) => void;
    onSave: (item: MediaItem, patch: { name?: string; alt?: string | null }) => void;
    onDelete?: (item: MediaItem) => void;
}) {
    const [name, setName] = useState(item.name);
    const [alt, setAlt] = useState(item.alt ?? '');
    const changed = name.trim() !== item.name || alt !== (item.alt ?? '');

    return (
        <div className="grid gap-3 text-sm">
            <a href={item.url} target="_blank" rel="noopener" className="block overflow-hidden rounded-lg border border-border bg-muted/30">
                <img src={item.thumb} alt={item.alt ?? ''} className="max-h-48 w-full object-contain" />
            </a>

            {onPick && <Button type="button" onClick={() => onPick({ ...item, alt: alt || item.alt })}>{pickLabel}</Button>}

            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                <dt className="text-muted-foreground">Size</dt><dd>{item.width && item.height ? `${item.width} × ${item.height} · ` : ''}{formatBytes(item.size)}</dd>
                <dt className="text-muted-foreground">Uploaded</dt><dd>{item.uploaded}{item.uploader ? ` by ${item.uploader}` : ''}</dd>
            </dl>

            <div className="grid gap-1">
                <span className="text-xs font-medium">Image URL</span>
                <div className="flex gap-1.5">
                    <input readOnly value={item.url} onFocus={(e) => e.currentTarget.select()} aria-label="Image URL" className={`${input} font-mono text-xs`} />
                    <Button type="button" variant="outline" size="icon" className="size-9 shrink-0" onClick={() => onCopy(item)} aria-label="Copy image URL"><Copy className="size-4" /></Button>
                </div>
            </div>

            {item.editable && (
                <>
                    <label className="grid gap-1">
                        <span className="text-xs font-medium">Name</span>
                        <input value={name} onChange={(e) => setName(e.target.value)} className={input} />
                    </label>
                    <label className="grid gap-1">
                        <span className="text-xs font-medium">Alt text</span>
                        <textarea value={alt} onChange={(e) => setAlt(e.target.value)} rows={2} placeholder="Describe the image for screen readers and search"
                            className="w-full rounded-lg border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20" />
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" size="sm" disabled={!changed || !name.trim()} onClick={() => onSave(item, { name: name.trim(), alt: alt || null })}>Save details</Button>
                        {onDelete && (
                            <Button type="button" variant="ghost" size="sm" className="text-destructive hover:text-destructive" onClick={() => onDelete(item)}>
                                <Trash2 className="size-4" /> Delete
                            </Button>
                        )}
                    </div>
                </>
            )}
        </div>
    );
}
