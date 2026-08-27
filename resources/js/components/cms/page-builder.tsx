import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { RichEditor } from '@/components/rich-editor';
import { uploadImage } from '@/lib/upload';
import type { PageBlock, PageColumn, PageSection } from '@/components/cms/page-sections';
import { ArrowDown, ArrowUp, Columns2, ImagePlus, LayoutGrid, Plus, Square, Trash2, Type } from 'lucide-react';

/* ------------------------------------------------------------------ helpers */

const uid = (p = 'b') => `${p}_${Math.random().toString(36).slice(2, 9)}`;

export function emptySection(columns = 1): PageSection {
    return { id: uid('s'), title: '', columns: Array.from({ length: columns }, () => ({ blocks: [] as PageBlock[] })) };
}

const newBlock = (type: PageBlock['type']): PageBlock =>
    type === 'image' ? { id: uid(), type: 'image', url: '', alt: '' } : { id: uid(), type: 'richtext', html: '' };

/** Make sure loaded layout has ids everywhere so React keys + reorder are stable. */
export function normalizeSections(raw?: PageSection[] | null): PageSection[] {
    if (!raw || raw.length === 0) return [];
    return raw.map((s) => ({
        id: s.id || uid('s'),
        title: s.title ?? '',
        columns: (s.columns?.length ? s.columns : [{ blocks: [] }]).map((c) => ({
            blocks: (c.blocks ?? []).map((b) => ({ ...b, id: b.id || uid() })),
        })),
    }));
}

/** Flatten section rich-text into a single HTML string (legacy `body`, SEO/excerpt fallback). */
export function sectionsToHtml(sections: PageSection[]): string {
    return sections
        .flatMap((s) => [
            s.title ? `<h2>${s.title}</h2>` : '',
            ...s.columns.flatMap((c) => c.blocks.map((b) => (b.type === 'richtext' ? b.html : b.url ? `<img src="${b.url}" alt="${b.alt}">` : ''))),
        ])
        .filter(Boolean)
        .join('\n');
}

/* ------------------------------------------------------------------- editor */

export function PageBuilder({ value, onChange }: { value: PageSection[]; onChange: (s: PageSection[]) => void }) {
    const mutate = (fn: (draft: PageSection[]) => void) => {
        const draft = JSON.parse(JSON.stringify(value)) as PageSection[];
        fn(draft);
        onChange(draft);
    };

    const setColumns = (si: number, count: 1 | 2) =>
        mutate((d) => {
            const s = d[si];
            if (count === 2 && s.columns.length === 1) s.columns.push({ blocks: [] });
            if (count === 1 && s.columns.length === 2) {
                s.columns[0].blocks = [...s.columns[0].blocks, ...s.columns[1].blocks]; // keep content
                s.columns = [s.columns[0]];
            }
        });

    const move = <T,>(arr: T[], i: number, dir: -1 | 1) => {
        const j = i + dir;
        if (j < 0 || j >= arr.length) return;
        [arr[i], arr[j]] = [arr[j], arr[i]];
    };

    return (
        <div className="grid gap-5">
            {value.length === 0 && (
                <div className="rounded-2xl border border-dashed border-border p-10 text-center">
                    <LayoutGrid className="mx-auto size-7 text-muted-foreground" />
                    <p className="mt-3 text-sm font-medium">Build your page in sections</p>
                    <p className="mt-1 text-sm text-muted-foreground">Add a section, choose one or two columns, then drop in text and images.</p>
                </div>
            )}

            {value.map((section, si) => (
                <section key={section.id} className="rounded-2xl border border-border bg-card p-4 shadow-sm">
                    {/* Section toolbar */}
                    <div className="mb-4 flex flex-wrap items-center gap-2">
                        <span className="rounded-md bg-secondary px-2 py-1 text-xs font-semibold text-muted-foreground">Section {si + 1}</span>
                        <input
                            className="h-9 min-w-0 flex-1 rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                            placeholder="Section title (optional)"
                            value={section.title}
                            onChange={(e) => mutate((d) => { d[si].title = e.target.value; })}
                        />
                        <div className="flex items-center rounded-lg border border-border p-0.5">
                            <button type="button" title="One column" onClick={() => setColumns(si, 1)} className={`flex size-8 items-center justify-center rounded-md ${section.columns.length === 1 ? 'bg-foreground text-background' : 'hover:bg-accent'}`}><Square className="size-4" /></button>
                            <button type="button" title="Two columns" onClick={() => setColumns(si, 2)} className={`flex size-8 items-center justify-center rounded-md ${section.columns.length === 2 ? 'bg-foreground text-background' : 'hover:bg-accent'}`}><Columns2 className="size-4" /></button>
                        </div>
                        <div className="flex items-center">
                            <button type="button" aria-label="Move section up" disabled={si === 0} onClick={() => mutate((d) => move(d, si, -1))} className="flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowUp className="size-4" /></button>
                            <button type="button" aria-label="Move section down" disabled={si === value.length - 1} onClick={() => mutate((d) => move(d, si, 1))} className="flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowDown className="size-4" /></button>
                            <button type="button" aria-label="Delete section" onClick={() => mutate((d) => d.splice(si, 1))} className="flex size-8 items-center justify-center rounded-md text-destructive hover:bg-destructive/10"><Trash2 className="size-4" /></button>
                        </div>
                    </div>

                    {/* Columns */}
                    <div className={section.columns.length === 2 ? 'grid gap-4 md:grid-cols-2' : 'grid gap-4'}>
                        {section.columns.map((col, ci) => (
                            <div key={ci} className="rounded-xl border border-dashed border-border bg-muted/30 p-3">
                                <div className="grid gap-3">
                                    {col.blocks.map((block, bi) => (
                                        <BlockEditor
                                            key={block.id}
                                            block={block}
                                            first={bi === 0}
                                            last={bi === col.blocks.length - 1}
                                            onChange={(patch) => mutate((d) => { Object.assign(d[si].columns[ci].blocks[bi], patch); })}
                                            onMove={(dir) => mutate((d) => move(d[si].columns[ci].blocks, bi, dir))}
                                            onRemove={() => mutate((d) => d[si].columns[ci].blocks.splice(bi, 1))}
                                        />
                                    ))}
                                </div>
                                <div className="mt-3 flex gap-2">
                                    <Button type="button" variant="outline" size="sm" onClick={() => mutate((d) => d[si].columns[ci].blocks.push(newBlock('richtext')))}><Type className="size-4" /> Text</Button>
                                    <Button type="button" variant="outline" size="sm" onClick={() => mutate((d) => d[si].columns[ci].blocks.push(newBlock('image')))}><ImagePlus className="size-4" /> Image</Button>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            ))}

            <div className="flex flex-wrap gap-3">
                <Button type="button" variant="outline" onClick={() => onChange([...value, emptySection(1)])}><Plus className="size-4" /> Add section</Button>
                <Button type="button" variant="outline" onClick={() => onChange([...value, emptySection(2)])}><Columns2 className="size-4" /> Add 2-column section</Button>
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------- block card */

function BlockEditor({
    block, first, last, onChange, onMove, onRemove,
}: {
    block: PageBlock;
    first: boolean;
    last: boolean;
    onChange: (patch: Partial<PageBlock>) => void;
    onMove: (dir: -1 | 1) => void;
    onRemove: () => void;
}) {
    return (
        <div className="rounded-lg border border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-2 py-1.5">
                <span className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    {block.type === 'image' ? <ImagePlus className="size-3.5" /> : <Type className="size-3.5" />}
                    {block.type === 'image' ? 'Image' : 'Text'}
                </span>
                <div className="flex items-center">
                    <button type="button" aria-label="Move up" disabled={first} onClick={() => onMove(-1)} className="flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowUp className="size-3.5" /></button>
                    <button type="button" aria-label="Move down" disabled={last} onClick={() => onMove(1)} className="flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowDown className="size-3.5" /></button>
                    <button type="button" aria-label="Remove block" onClick={onRemove} className="flex size-7 items-center justify-center rounded text-destructive hover:bg-destructive/10"><Trash2 className="size-3.5" /></button>
                </div>
            </div>
            <div className="p-2">
                {block.type === 'image'
                    ? <ImageBlock url={block.url} alt={block.alt} onChange={onChange} />
                    : <RichEditor value={block.html} onChange={(html) => onChange({ html })} placeholder="Write…" />}
            </div>
        </div>
    );
}

function ImageBlock({ url, alt, onChange }: { url: string; alt: string; onChange: (patch: Partial<PageBlock>) => void }) {
    const [uploading, setUploading] = useState(false);

    const pick = async (file: File | undefined) => {
        if (!file) return;
        setUploading(true);
        try {
            onChange({ url: await uploadImage(file) });
        } catch {
            /* leave as-is on failure */
        } finally {
            setUploading(false);
        }
    };

    return (
        <div className="grid gap-2">
            {url
                ? <img src={url} alt={alt} className="w-full rounded-lg border border-border" />
                : (
                    <label className="flex aspect-[16/9] w-full cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border text-sm text-muted-foreground transition-colors hover:border-foreground/40 hover:text-foreground">
                        <ImagePlus className="size-6" />
                        {uploading ? 'Uploading…' : 'Upload image'}
                        <input type="file" accept="image/*" hidden onChange={(e) => pick(e.target.files?.[0])} />
                    </label>
                )}
            <div className="flex gap-2">
                <input
                    className="h-9 min-w-0 flex-1 rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                    placeholder="Alt text / caption"
                    value={alt}
                    onChange={(e) => onChange({ alt: e.target.value })}
                />
                {url && <Button type="button" variant="outline" size="sm" onClick={() => onChange({ url: '' })}>Replace</Button>}
            </div>
        </div>
    );
}
