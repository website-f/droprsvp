import { useState, type CSSProperties } from 'react';
import { DndContext, PointerSensor, closestCenter, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { SortableContext, arrayMove, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Button } from '@/components/ui/button';
import { RichEditor } from '@/components/rich-editor';
import { uploadImage } from '@/lib/upload';
import { DEFAULT_SETTINGS, type Align, type PageBlock, type PageColumn, type PageSection, type SectionSettings } from '@/components/cms/page-sections';
import {
    AlignCenter, AlignLeft, AlignRight, ArrowDown, ArrowUp, Columns2, Copy, GripVertical, Heading, Image as ImageIcon,
    LayoutGrid, Minus, MousePointerClick, Plus, Settings2, SquareStack, Trash2, Type, Video as VideoIcon,
} from 'lucide-react';

/* ------------------------------------------------------------------ helpers */

const uid = (p = 'b') => `${p}_${Math.random().toString(36).slice(2, 9)}`;

function newBlock(type: PageBlock['type']): PageBlock {
    switch (type) {
        case 'heading': return { id: uid(), type: 'heading', text: 'Heading', level: 2, align: 'left' };
        case 'image': return { id: uid(), type: 'image', url: '', alt: '', rounded: true };
        case 'button': return { id: uid(), type: 'button', label: 'Learn more', url: '', variant: 'primary', align: 'left', newTab: false };
        case 'divider': return { id: uid(), type: 'divider' };
        case 'spacer': return { id: uid(), type: 'spacer', size: 'md' };
        case 'video': return { id: uid(), type: 'video', url: '' };
        default: return { id: uid(), type: 'richtext', html: '' };
    }
}

export function emptySection(cols = 1): PageSection {
    return {
        id: uid('s'),
        columns: Array.from({ length: cols }, () => ({ blocks: [] as PageBlock[] })),
        settings: { ...DEFAULT_SETTINGS, cols },
    };
}

export function normalizeSections(raw?: PageSection[] | null): PageSection[] {
    if (!raw || raw.length === 0) return [];
    return raw.map((s) => {
        const columns = (s.columns?.length ? s.columns : [{ blocks: [] }]).map((c) => ({
            width: c.width,
            blocks: (c.blocks ?? []).map((b) => ({ ...b, id: b.id || uid() })),
        }));
        return {
            id: s.id || uid('s'),
            title: s.title,
            columns,
            settings: { ...DEFAULT_SETTINGS, ...(s.settings ?? {}), cols: columns.length },
        };
    });
}

export function sectionsToHtml(sections: PageSection[]): string {
    const blockHtml = (b: PageBlock): string => {
        switch (b.type) {
            case 'heading': return `<h${b.level}>${b.text}</h${b.level}>`;
            case 'richtext': return b.html;
            case 'image': return b.url ? `<img src="${b.url}" alt="${b.alt}">` : '';
            case 'button': return b.url ? `<a href="${b.url}">${b.label}</a>` : '';
            case 'divider': return '<hr>';
            default: return '';
        }
    };
    return sections
        .flatMap((s) => [s.title ? `<h2>${s.title}</h2>` : '', ...s.columns.flatMap((c) => c.blocks.map(blockHtml))])
        .filter(Boolean)
        .join('\n');
}

const COL_PRESETS: Record<number, { label: string; widths: string[] }[]> = {
    2: [
        { label: '50 / 50', widths: ['1', '1'] },
        { label: '33 / 67', widths: ['1', '2'] },
        { label: '67 / 33', widths: ['2', '1'] },
        { label: '40 / 60', widths: ['2', '3'] },
        { label: '60 / 40', widths: ['3', '2'] },
    ],
};

const WIDGETS: { type: PageBlock['type']; label: string; icon: typeof Type }[] = [
    { type: 'heading', label: 'Heading', icon: Heading },
    { type: 'richtext', label: 'Text', icon: Type },
    { type: 'image', label: 'Image', icon: ImageIcon },
    { type: 'button', label: 'Button', icon: MousePointerClick },
    { type: 'video', label: 'Video', icon: VideoIcon },
    { type: 'divider', label: 'Divider', icon: Minus },
    { type: 'spacer', label: 'Spacer', icon: SquareStack },
];

const ctrl = 'h-9 rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20';

/* -------------------------------------------------------------- component */

export function PageBuilder({ value, onChange }: { value: PageSection[]; onChange: (s: PageSection[]) => void }) {
    const [openSettings, setOpenSettings] = useState<string | null>(null);
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));

    const mutate = (fn: (draft: PageSection[]) => void) => {
        const draft = JSON.parse(JSON.stringify(value)) as PageSection[];
        fn(draft);
        onChange(draft);
    };

    const setCols = (si: number, cols: number) =>
        mutate((d) => {
            const s = d[si];
            const cur = s.columns.length;
            if (cols > cur) for (let i = cur; i < cols; i++) s.columns.push({ blocks: [] });
            if (cols < cur) {
                const spill = s.columns.slice(cols).flatMap((c) => c.blocks);
                s.columns = s.columns.slice(0, cols);
                s.columns[cols - 1].blocks.push(...spill); // keep content
            }
            s.settings.cols = cols;
            if (cols !== 2) delete s.settings.colWidths;
        });

    const onDragEnd = (e: DragEndEvent) => {
        const { active, over } = e;
        if (!over || active.id === over.id) return;
        const from = value.findIndex((s) => s.id === active.id);
        const to = value.findIndex((s) => s.id === over.id);
        if (from < 0 || to < 0) return;
        onChange(arrayMove(value, from, to));
    };

    return (
        <div className="grid gap-4">
            {value.length === 0 && (
                <div className="rounded-2xl border border-dashed border-border p-10 text-center">
                    <LayoutGrid className="mx-auto size-7 text-muted-foreground" />
                    <p className="mt-3 text-sm font-medium">Build your page in sections</p>
                    <p className="mt-1 text-sm text-muted-foreground">Add a section, choose columns, drop in widgets, then drag sections to reorder.</p>
                </div>
            )}

            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
                <SortableContext items={value.map((s) => s.id)} strategy={verticalListSortingStrategy}>
                    <div className="grid gap-4">
                        {value.map((section, si) => (
                            <SortableSection
                                key={section.id}
                                section={section}
                                index={si}
                                total={value.length}
                                settingsOpen={openSettings === section.id}
                                onToggleSettings={() => setOpenSettings(openSettings === section.id ? null : section.id)}
                                setCols={(c) => setCols(si, c)}
                                onPatchSettings={(patch) => mutate((d) => { Object.assign(d[si].settings, patch); })}
                                onMove={(dir) => mutate((d) => { const j = si + dir; if (j >= 0 && j < d.length) [d[si], d[j]] = [d[j], d[si]]; })}
                                onDuplicate={() => mutate((d) => { const copy = JSON.parse(JSON.stringify(d[si])); copy.id = uid('s'); copy.columns.forEach((c: PageColumn) => c.blocks.forEach((b) => (b.id = uid()))); d.splice(si + 1, 0, copy); })}
                                onDelete={() => mutate((d) => d.splice(si, 1))}
                                onAddBlock={(ci, type) => mutate((d) => d[si].columns[ci].blocks.push(newBlock(type)))}
                                onPatchBlock={(ci, bi, patch) => mutate((d) => { Object.assign(d[si].columns[ci].blocks[bi], patch); })}
                                onMoveBlock={(ci, bi, dir) => mutate((d) => { const arr = d[si].columns[ci].blocks; const j = bi + dir; if (j >= 0 && j < arr.length) [arr[bi], arr[j]] = [arr[j], arr[bi]]; })}
                                onDupBlock={(ci, bi) => mutate((d) => { const copy = JSON.parse(JSON.stringify(d[si].columns[ci].blocks[bi])); copy.id = uid(); d[si].columns[ci].blocks.splice(bi + 1, 0, copy); })}
                                onDelBlock={(ci, bi) => mutate((d) => d[si].columns[ci].blocks.splice(bi, 1))}
                            />
                        ))}
                    </div>
                </SortableContext>
            </DndContext>

            <div className="flex flex-wrap gap-3">
                <Button type="button" variant="outline" onClick={() => onChange([...value, emptySection(1)])}><Plus className="size-4" /> Add section</Button>
                <Button type="button" variant="outline" onClick={() => onChange([...value, emptySection(2)])}><Columns2 className="size-4" /> 2-column section</Button>
            </div>
        </div>
    );
}

/* --------------------------------------------------------- section card */

interface SectionProps {
    section: PageSection;
    index: number;
    total: number;
    settingsOpen: boolean;
    onToggleSettings: () => void;
    setCols: (c: number) => void;
    onPatchSettings: (patch: Partial<SectionSettings>) => void;
    onMove: (dir: -1 | 1) => void;
    onDuplicate: () => void;
    onDelete: () => void;
    onAddBlock: (ci: number, type: PageBlock['type']) => void;
    onPatchBlock: (ci: number, bi: number, patch: Record<string, unknown>) => void;
    onMoveBlock: (ci: number, bi: number, dir: -1 | 1) => void;
    onDupBlock: (ci: number, bi: number) => void;
    onDelBlock: (ci: number, bi: number) => void;
}

function SortableSection(p: SectionProps) {
    const { section: s, index: si } = p;
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: s.id });
    const style: CSSProperties = { transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.6 : 1 };
    const set = { ...DEFAULT_SETTINGS, ...s.settings };
    const [palette, setPalette] = useState<number | null>(null);

    return (
        <section ref={setNodeRef} style={style} className="rounded-2xl border border-border bg-card p-4 shadow-sm">
            {/* Section toolbar */}
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <button type="button" className="flex size-8 cursor-grab items-center justify-center rounded-md text-muted-foreground hover:bg-accent active:cursor-grabbing" {...attributes} {...listeners} aria-label="Drag section"><GripVertical className="size-4" /></button>
                <span className="rounded-md bg-secondary px-2 py-1 text-xs font-semibold text-muted-foreground">Section {si + 1}</span>

                {/* column count */}
                <div className="flex items-center rounded-lg border border-border p-0.5">
                    {[1, 2, 3, 4].map((n) => (
                        <button key={n} type="button" title={`${n} column${n > 1 ? 's' : ''}`} onClick={() => p.setCols(n)}
                            className={`flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-xs font-semibold ${set.cols === n ? 'bg-foreground text-background' : 'hover:bg-accent'}`}>{n}</button>
                    ))}
                </div>

                <div className="ml-auto flex items-center">
                    <button type="button" aria-label="Section settings" onClick={p.onToggleSettings} className={`flex size-8 items-center justify-center rounded-md ${p.settingsOpen ? 'bg-accent' : 'hover:bg-accent'}`}><Settings2 className="size-4" /></button>
                    <button type="button" aria-label="Move up" disabled={si === 0} onClick={() => p.onMove(-1)} className="flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowUp className="size-4" /></button>
                    <button type="button" aria-label="Move down" disabled={si === p.total - 1} onClick={() => p.onMove(1)} className="flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowDown className="size-4" /></button>
                    <button type="button" aria-label="Duplicate" onClick={p.onDuplicate} className="flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent"><Copy className="size-4" /></button>
                    <button type="button" aria-label="Delete section" onClick={p.onDelete} className="flex size-8 items-center justify-center rounded-md text-destructive hover:bg-destructive/10"><Trash2 className="size-4" /></button>
                </div>
            </div>

            {/* Settings panel */}
            {p.settingsOpen && (
                <div className="mb-4 grid gap-3 rounded-xl border border-dashed border-border bg-muted/30 p-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label="Background">
                        <div className="flex items-center gap-2">
                            <input type="color" value={set.bg || '#ffffff'} onChange={(e) => p.onPatchSettings({ bg: e.target.value })} className="h-9 w-12 rounded-md border border-input bg-background p-1" />
                            <input className={`${ctrl} flex-1`} value={set.bg} placeholder="#hex or empty" onChange={(e) => p.onPatchSettings({ bg: e.target.value })} />
                            {set.bg && <Button type="button" variant="ghost" size="sm" onClick={() => p.onPatchSettings({ bg: '' })}>Clear</Button>}
                        </div>
                    </Field>
                    <Field label="Vertical padding"><Segment value={set.padY} onChange={(v) => p.onPatchSettings({ padY: v as SectionSettings['padY'] })} options={[['none', 'None'], ['sm', 'S'], ['md', 'M'], ['lg', 'L'], ['xl', 'XL']]} /></Field>
                    <Field label="Content width"><Segment value={set.width} onChange={(v) => p.onPatchSettings({ width: v as SectionSettings['width'] })} options={[['boxed', 'Boxed'], ['wide', 'Wide'], ['full', 'Full']]} /></Field>
                    <Field label="Text align"><AlignSeg value={set.align} onChange={(v) => p.onPatchSettings({ align: v })} /></Field>
                    <Field label="Column gap"><Segment value={set.gap} onChange={(v) => p.onPatchSettings({ gap: v as SectionSettings['gap'] })} options={[['sm', 'S'], ['md', 'M'], ['lg', 'L']]} /></Field>
                    <Field label="Vertical align"><Segment value={set.valign} onChange={(v) => p.onPatchSettings({ valign: v as SectionSettings['valign'] })} options={[['top', 'Top'], ['center', 'Middle'], ['bottom', 'Bottom']]} /></Field>
                    {set.cols === 2 && (
                        <Field label="Column split" full>
                            <div className="flex flex-wrap gap-2">
                                {COL_PRESETS[2].map((preset) => {
                                    const active = (set.colWidths ?? ['1', '1']).join('/') === preset.widths.join('/');
                                    return <button key={preset.label} type="button" onClick={() => p.onPatchSettings({ colWidths: preset.widths })} className={`rounded-full border px-3 py-1 text-xs font-medium ${active ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>{preset.label}</button>;
                                })}
                            </div>
                        </Field>
                    )}
                </div>
            )}

            {/* Columns */}
            <div className={`grid gap-3 ${set.cols === 1 ? '' : set.cols === 2 ? 'md:grid-cols-2' : set.cols === 3 ? 'md:grid-cols-3' : 'md:grid-cols-2 xl:grid-cols-4'}`}>
                {s.columns.map((col, ci) => (
                    <div key={ci} className="rounded-xl border border-dashed border-border bg-muted/30 p-3">
                        <div className="grid gap-3">
                            {col.blocks.map((block, bi) => (
                                <BlockEditor
                                    key={block.id}
                                    block={block}
                                    first={bi === 0}
                                    last={bi === col.blocks.length - 1}
                                    onPatch={(patch) => p.onPatchBlock(ci, bi, patch)}
                                    onMove={(dir) => p.onMoveBlock(ci, bi, dir)}
                                    onDup={() => p.onDupBlock(ci, bi)}
                                    onDel={() => p.onDelBlock(ci, bi)}
                                />
                            ))}
                        </div>
                        {/* widget palette */}
                        <div className="mt-3">
                            {palette === ci ? (
                                <div className="grid grid-cols-2 gap-1.5 rounded-lg border border-border bg-card p-2 sm:grid-cols-4">
                                    {WIDGETS.map((w) => (
                                        <button key={w.type} type="button" onClick={() => { p.onAddBlock(ci, w.type); setPalette(null); }}
                                            className="flex flex-col items-center gap-1 rounded-md p-2 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
                                            <w.icon className="size-4" />{w.label}
                                        </button>
                                    ))}
                                </div>
                            ) : (
                                <Button type="button" variant="outline" size="sm" className="w-full" onClick={() => setPalette(ci)}><Plus className="size-4" /> Add widget</Button>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

/* ---------------------------------------------------------- block editor */

function BlockEditor({ block, first, last, onPatch, onMove, onDup, onDel }: {
    block: PageBlock;
    first: boolean;
    last: boolean;
    onPatch: (patch: Record<string, unknown>) => void;
    onMove: (dir: -1 | 1) => void;
    onDup: () => void;
    onDel: () => void;
}) {
    const label = block.type === 'richtext' ? 'Text' : block.type[0].toUpperCase() + block.type.slice(1);
    return (
        <div className="rounded-lg border border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-2 py-1.5">
                <span className="text-xs font-medium text-muted-foreground">{label}</span>
                <div className="flex items-center">
                    <button type="button" aria-label="Move up" disabled={first} onClick={() => onMove(-1)} className="flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowUp className="size-3.5" /></button>
                    <button type="button" aria-label="Move down" disabled={last} onClick={() => onMove(1)} className="flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowDown className="size-3.5" /></button>
                    <button type="button" aria-label="Duplicate" onClick={onDup} className="flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-accent"><Copy className="size-3.5" /></button>
                    <button type="button" aria-label="Delete" onClick={onDel} className="flex size-7 items-center justify-center rounded text-destructive hover:bg-destructive/10"><Trash2 className="size-3.5" /></button>
                </div>
            </div>
            <div className="p-2"><BlockFields block={block} onPatch={onPatch} /></div>
        </div>
    );
}

function BlockFields({ block, onPatch }: { block: PageBlock; onPatch: (patch: Record<string, unknown>) => void }) {
    switch (block.type) {
        case 'heading':
            return (
                <div className="grid gap-2">
                    <input className={`${ctrl} w-full`} value={block.text} onChange={(e) => onPatch({ text: e.target.value })} placeholder="Heading text" />
                    <div className="flex items-center gap-2">
                        <Segment value={String(block.level)} onChange={(v) => onPatch({ level: Number(v) })} options={[['2', 'H2'], ['3', 'H3'], ['4', 'H4']]} />
                        <AlignSeg value={block.align} onChange={(v) => onPatch({ align: v })} />
                    </div>
                </div>
            );
        case 'richtext':
            return <RichEditor value={block.html} onChange={(html) => onPatch({ html })} placeholder="Write…" />;
        case 'image':
            return <ImageFields url={block.url} alt={block.alt} rounded={block.rounded} onPatch={onPatch} />;
        case 'button':
            return (
                <div className="grid gap-2">
                    <input className={`${ctrl} w-full`} value={block.label} onChange={(e) => onPatch({ label: e.target.value })} placeholder="Button label" />
                    <input className={`${ctrl} w-full`} value={block.url} onChange={(e) => onPatch({ url: e.target.value })} placeholder="/link or https://…" />
                    <div className="flex flex-wrap items-center gap-2">
                        <Segment value={block.variant} onChange={(v) => onPatch({ variant: v })} options={[['primary', 'Solid'], ['outline', 'Outline']]} />
                        <AlignSeg value={block.align} onChange={(v) => onPatch({ align: v })} />
                        <label className="flex items-center gap-1.5 text-xs"><input type="checkbox" className="size-3.5 rounded border-input" checked={block.newTab} onChange={(e) => onPatch({ newTab: e.target.checked })} /> New tab</label>
                    </div>
                </div>
            );
        case 'spacer':
            return <Segment value={block.size} onChange={(v) => onPatch({ size: v })} options={[['sm', 'S'], ['md', 'M'], ['lg', 'L'], ['xl', 'XL']]} />;
        case 'video':
            return <input className={`${ctrl} w-full`} value={block.url} onChange={(e) => onPatch({ url: e.target.value })} placeholder="YouTube / Vimeo / embed URL" />;
        case 'divider':
            return <p className="px-1 text-xs text-muted-foreground">A horizontal divider line.</p>;
        default:
            return null;
    }
}

function ImageFields({ url, alt, rounded, onPatch }: { url: string; alt: string; rounded: boolean; onPatch: (p: Record<string, unknown>) => void }) {
    const [uploading, setUploading] = useState(false);
    const pick = async (file: File | undefined) => {
        if (!file) return;
        setUploading(true);
        try { onPatch({ url: await uploadImage(file) }); } catch { /* keep */ } finally { setUploading(false); }
    };
    return (
        <div className="grid gap-2">
            {url
                ? <img src={url} alt={alt} className={`w-full border border-border ${rounded ? 'rounded-lg' : ''}`} />
                : (
                    <label className="flex aspect-[16/9] w-full cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border text-sm text-muted-foreground hover:border-foreground/40 hover:text-foreground">
                        <ImageIcon className="size-6" />{uploading ? 'Uploading…' : 'Upload image'}
                        <input type="file" accept="image/*" hidden onChange={(e) => pick(e.target.files?.[0])} />
                    </label>
                )}
            <input className={`${ctrl} w-full`} value={alt} onChange={(e) => onPatch({ alt: e.target.value })} placeholder="Alt text / caption" />
            <div className="flex items-center gap-2">
                <label className="flex items-center gap-1.5 text-xs"><input type="checkbox" className="size-3.5 rounded border-input" checked={rounded} onChange={(e) => onPatch({ rounded: e.target.checked })} /> Rounded</label>
                {url && <Button type="button" variant="ghost" size="sm" onClick={() => onPatch({ url: '' })}>Replace</Button>}
            </div>
        </div>
    );
}

/* -------------------------------------------------------------- controls */

function Field({ label, children, full }: { label: string; children: React.ReactNode; full?: boolean }) {
    return (
        <div className={`grid gap-1.5 ${full ? 'sm:col-span-2 lg:col-span-3' : ''}`}>
            <span className="text-xs font-medium text-muted-foreground">{label}</span>
            {children}
        </div>
    );
}

function Segment({ value, onChange, options }: { value: string; onChange: (v: string) => void; options: [string, string][] }) {
    return (
        <div className="flex items-center rounded-lg border border-border p-0.5">
            {options.map(([val, lbl]) => (
                <button key={val} type="button" onClick={() => onChange(val)} className={`flex h-7 items-center justify-center rounded-md px-2.5 text-xs font-medium ${value === val ? 'bg-foreground text-background' : 'hover:bg-accent'}`}>{lbl}</button>
            ))}
        </div>
    );
}

function AlignSeg({ value, onChange }: { value: Align; onChange: (v: Align) => void }) {
    const opts: [Align, typeof AlignLeft][] = [['left', AlignLeft], ['center', AlignCenter], ['right', AlignRight]];
    return (
        <div className="flex items-center rounded-lg border border-border p-0.5">
            {opts.map(([val, Icon]) => (
                <button key={val} type="button" aria-label={`Align ${val}`} onClick={() => onChange(val)} className={`flex size-7 items-center justify-center rounded-md ${value === val ? 'bg-foreground text-background' : 'hover:bg-accent'}`}><Icon className="size-3.5" /></button>
            ))}
        </div>
    );
}
