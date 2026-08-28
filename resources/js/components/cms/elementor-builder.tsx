import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { BlockEditor, Field, Segment, AlignSeg, WIDGETS, newBlock, emptySection } from '@/components/cms/page-builder';
import { DEFAULT_SETTINGS, type PageBlock, type PageColumn, type PageSection, type SectionSettings } from '@/components/cms/page-sections';
import {
    ArrowDown, ArrowUp, Columns2, Copy, LayoutGrid, Layers, Plus, Rows3, SlidersHorizontal, Trash2, X,
} from 'lucide-react';

const uid = (p = 'b') => `${p}_${Math.random().toString(36).slice(2, 9)}`;
const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v));

const COL_SPLITS: { label: string; widths: string[] }[] = [
    { label: '50 / 50', widths: ['1', '1'] },
    { label: '33 / 67', widths: ['1', '2'] },
    { label: '67 / 33', widths: ['2', '1'] },
    { label: '40 / 60', widths: ['2', '3'] },
    { label: '60 / 40', widths: ['3', '2'] },
];

/**
 * Elementor-style editor: a collapsible LEFT panel (Add widgets / Edit the
 * selected section) and a selectable canvas. Fully responsive — the panel
 * becomes a drawer on mobile.
 */
export function ElementorBuilder({ value, onChange }: { value: PageSection[]; onChange: (s: PageSection[]) => void }) {
    const [selSi, setSelSi] = useState<number | null>(null);
    const [active, setActive] = useState<{ si: number; ci: number } | null>(null);
    const [tab, setTab] = useState<'add' | 'edit'>('add');
    const [panelOpen, setPanelOpen] = useState(true); // mobile drawer / desktop collapse

    const mutate = (fn: (d: PageSection[]) => void) => { const d = clone(value); fn(d); onChange(d); };

    const select = (si: number) => { setSelSi(si); setActive({ si, ci: 0 }); setTab('edit'); };

    const addSection = (cols: number) => {
        const s = emptySection(cols);
        onChange([...value, s]);
        setSelSi(value.length); setActive({ si: value.length, ci: 0 });
    };

    const addWidget = (type: PageBlock['type']) => {
        let target = active;
        mutate((d) => {
            if (!target || !d[target.si]) {
                if (d.length === 0) d.push(emptySection(1));
                target = { si: d.length - 1, ci: 0 };
            }
            d[target.si].columns[target.ci].blocks.push(newBlock(type));
        });
        if (target) { setSelSi(target.si); setActive(target); }
    };

    const setCols = (si: number, cols: number) => mutate((d) => {
        const s = d[si]; const cur = s.columns.length;
        if (cols > cur) for (let i = cur; i < cols; i++) s.columns.push({ blocks: [] });
        if (cols < cur) { const spill = s.columns.slice(cols).flatMap((c) => c.blocks); s.columns = s.columns.slice(0, cols); s.columns[cols - 1].blocks.push(...spill); }
        s.settings.cols = cols;
        if (cols !== 2) delete s.settings.colWidths;
    });

    const patchSettings = (si: number, patch: Partial<SectionSettings>) => mutate((d) => { Object.assign(d[si].settings, patch); });
    const moveSection = (si: number, dir: -1 | 1) => mutate((d) => { const j = si + dir; if (j >= 0 && j < d.length) [d[si], d[j]] = [d[j], d[si]]; });
    const dupSection = (si: number) => mutate((d) => { const c = clone(d[si]); c.id = uid('s'); c.columns.forEach((col: PageColumn) => col.blocks.forEach((b) => (b.id = uid()))); d.splice(si + 1, 0, c); });
    const delSection = (si: number) => { mutate((d) => d.splice(si, 1)); setSelSi(null); setActive(null); };

    const sel = selSi != null ? value[selSi] : null;
    const set = sel ? { ...DEFAULT_SETTINGS, ...sel.settings } : DEFAULT_SETTINGS;

    return (
        <div className="relative flex h-full min-h-[70vh] overflow-hidden rounded-2xl border border-border bg-muted/20">
            {/* mobile open button */}
            {!panelOpen && (
                <button type="button" onClick={() => setPanelOpen(true)} className="absolute left-3 top-3 z-20 flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium shadow-sm lg:hidden">
                    <Layers className="size-4" /> Widgets
                </button>
            )}

            {/* Left panel */}
            <aside className={`${panelOpen ? 'translate-x-0' : '-translate-x-full lg:w-0 lg:-translate-x-full'} absolute inset-y-0 left-0 z-30 flex w-[300px] shrink-0 flex-col border-r border-border bg-card transition-transform lg:relative lg:z-0 ${panelOpen ? 'lg:w-[300px]' : ''}`}>
                <div className="flex items-center gap-1 border-b border-border p-2">
                    <button type="button" onClick={() => setTab('add')} className={`flex flex-1 items-center justify-center gap-1.5 rounded-md py-2 text-sm font-medium ${tab === 'add' ? 'bg-foreground text-background' : 'hover:bg-accent'}`}><Plus className="size-4" /> Add</button>
                    <button type="button" onClick={() => setTab('edit')} className={`flex flex-1 items-center justify-center gap-1.5 rounded-md py-2 text-sm font-medium ${tab === 'edit' ? 'bg-foreground text-background' : 'hover:bg-accent'}`}><SlidersHorizontal className="size-4" /> Edit</button>
                    <button type="button" aria-label="Collapse panel" onClick={() => setPanelOpen(false)} className="flex size-9 items-center justify-center rounded-md text-muted-foreground hover:bg-accent"><X className="size-4" /></button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto p-3">
                    {tab === 'add' ? (
                        <div className="grid gap-4">
                            <div>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Structure</p>
                                <div className="grid grid-cols-2 gap-2">
                                    <button type="button" onClick={() => addSection(1)} className="flex flex-col items-center gap-1.5 rounded-lg border border-border p-3 text-xs font-medium hover:border-foreground/40 hover:bg-accent"><Rows3 className="size-5" /> Section</button>
                                    <button type="button" onClick={() => addSection(2)} className="flex flex-col items-center gap-1.5 rounded-lg border border-border p-3 text-xs font-medium hover:border-foreground/40 hover:bg-accent"><Columns2 className="size-5" /> 2 columns</button>
                                </div>
                            </div>
                            <div>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Widgets</p>
                                <div className="grid grid-cols-2 gap-2">
                                    {WIDGETS.map((w) => (
                                        <button key={w.type} type="button" onClick={() => addWidget(w.type)} className="flex flex-col items-center gap-1.5 rounded-lg border border-border p-3 text-xs font-medium hover:border-foreground/40 hover:bg-accent"><w.icon className="size-5" /> {w.label}</button>
                                    ))}
                                </div>
                                <p className="mt-2 text-[11px] text-muted-foreground">Adds to the selected section{active ? '' : ' (select one first)'}.</p>
                            </div>
                        </div>
                    ) : sel ? (
                        <div className="grid gap-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Section {selSi! + 1}</p>
                            <Field label="Columns"><Segment value={String(set.cols)} onChange={(v) => setCols(selSi!, Number(v))} options={[['1', '1'], ['2', '2'], ['3', '3'], ['4', '4']]} /></Field>
                            {set.cols === 2 && (
                                <Field label="Column split">
                                    <div className="flex flex-wrap gap-1.5">
                                        {COL_SPLITS.map((p) => {
                                            const on = (set.colWidths ?? ['1', '1']).join('/') === p.widths.join('/');
                                            return <button key={p.label} type="button" onClick={() => patchSettings(selSi!, { colWidths: p.widths })} className={`rounded-full border px-2.5 py-1 text-xs font-medium ${on ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>{p.label}</button>;
                                        })}
                                    </div>
                                </Field>
                            )}
                            <Field label="Background">
                                <div className="flex items-center gap-2">
                                    <input type="color" value={set.bg || '#ffffff'} onChange={(e) => patchSettings(selSi!, { bg: e.target.value })} className="h-9 w-12 rounded-md border border-input bg-background p-1" />
                                    <input className="h-9 flex-1 rounded-lg border border-input bg-background px-3 text-sm outline-none" value={set.bg} placeholder="#hex or empty" onChange={(e) => patchSettings(selSi!, { bg: e.target.value })} />
                                    {set.bg && <Button type="button" variant="ghost" size="sm" onClick={() => patchSettings(selSi!, { bg: '' })}>Clear</Button>}
                                </div>
                            </Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Border width"><Segment value={String(set.borderW ?? 0)} onChange={(v) => patchSettings(selSi!, { borderW: Number(v) })} options={[['0', 'None'], ['1', '1'], ['2', '2'], ['4', '4']]} /></Field>
                                <Field label="Corner radius"><Segment value={String(set.radius ?? 0)} onChange={(v) => patchSettings(selSi!, { radius: Number(v) })} options={[['0', '0'], ['8', 'S'], ['16', 'M'], ['24', 'L']]} /></Field>
                            </div>
                            {!!set.borderW && (
                                <Field label="Border colour">
                                    <div className="flex items-center gap-2">
                                        <input type="color" value={set.borderColor || '#e5e5e6'} onChange={(e) => patchSettings(selSi!, { borderColor: e.target.value })} className="h-9 w-12 rounded-md border border-input bg-background p-1" />
                                        <input className="h-9 flex-1 rounded-lg border border-input bg-background px-3 text-sm outline-none" value={set.borderColor} onChange={(e) => patchSettings(selSi!, { borderColor: e.target.value })} />
                                    </div>
                                </Field>
                            )}
                            <Field label="Vertical padding"><Segment value={set.padY} onChange={(v) => patchSettings(selSi!, { padY: v as SectionSettings['padY'] })} options={[['none', 'None'], ['sm', 'S'], ['md', 'M'], ['lg', 'L'], ['xl', 'XL']]} /></Field>
                            <Field label="Content width"><Segment value={set.width} onChange={(v) => patchSettings(selSi!, { width: v as SectionSettings['width'] })} options={[['boxed', 'Boxed'], ['wide', 'Wide'], ['full', 'Full']]} /></Field>
                            <Field label="Gap"><Segment value={set.gap} onChange={(v) => patchSettings(selSi!, { gap: v as SectionSettings['gap'] })} options={[['sm', 'S'], ['md', 'M'], ['lg', 'L']]} /></Field>
                            <Field label="Text align"><AlignSeg value={set.align} onChange={(v) => patchSettings(selSi!, { align: v })} /></Field>
                            <Field label="Vertical align"><Segment value={set.valign} onChange={(v) => patchSettings(selSi!, { valign: v as SectionSettings['valign'] })} options={[['top', 'Top'], ['center', 'Mid'], ['bottom', 'Bot']]} /></Field>
                        </div>
                    ) : (
                        <div className="flex h-full flex-col items-center justify-center gap-2 py-16 text-center text-sm text-muted-foreground">
                            <SlidersHorizontal className="size-6" />
                            Select a section on the canvas to edit its layout, background and borders.
                        </div>
                    )}
                </div>
            </aside>

            {/* Canvas */}
            <div className="min-w-0 flex-1 overflow-y-auto p-4 sm:p-6" onClick={() => { setSelSi(null); setActive(null); }}>
                {value.length === 0 ? (
                    <div className="mx-auto max-w-md rounded-2xl border border-dashed border-border bg-card p-10 text-center">
                        <LayoutGrid className="mx-auto size-7 text-muted-foreground" />
                        <p className="mt-3 text-sm font-medium">Start building</p>
                        <p className="mt-1 text-sm text-muted-foreground">Add a section from the Widgets panel, then drop in headings, text and images.</p>
                        <Button type="button" className="mt-5" onClick={() => addSection(1)}><Plus className="size-4" /> Add section</Button>
                    </div>
                ) : (
                    <div className="mx-auto grid max-w-4xl gap-4">
                        {value.map((section, si) => (
                            <div
                                key={section.id}
                                onClick={(e) => { e.stopPropagation(); select(si); }}
                                className={`group rounded-xl border-2 bg-card p-3 transition-colors ${selSi === si ? 'border-foreground' : 'border-transparent hover:border-border'}`}
                            >
                                <div className="mb-2 flex items-center gap-2">
                                    <span className="rounded-md bg-secondary px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">Section {si + 1}</span>
                                    <div className="ml-auto flex items-center opacity-60 transition-opacity group-hover:opacity-100">
                                        <button type="button" aria-label="Move up" disabled={si === 0} onClick={(e) => { e.stopPropagation(); moveSection(si, -1); }} className="flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowUp className="size-3.5" /></button>
                                        <button type="button" aria-label="Move down" disabled={si === value.length - 1} onClick={(e) => { e.stopPropagation(); moveSection(si, 1); }} className="flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-accent disabled:opacity-30"><ArrowDown className="size-3.5" /></button>
                                        <button type="button" aria-label="Duplicate" onClick={(e) => { e.stopPropagation(); dupSection(si); }} className="flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-accent"><Copy className="size-3.5" /></button>
                                        <button type="button" aria-label="Delete section" onClick={(e) => { e.stopPropagation(); delSection(si); }} className="flex size-7 items-center justify-center rounded text-destructive hover:bg-destructive/10"><Trash2 className="size-3.5" /></button>
                                    </div>
                                </div>
                                <div className={`grid gap-3 ${section.columns.length === 2 ? 'md:grid-cols-2' : section.columns.length === 3 ? 'md:grid-cols-3' : section.columns.length === 4 ? 'md:grid-cols-2 xl:grid-cols-4' : ''}`}>
                                    {section.columns.map((col, ci) => (
                                        <div
                                            key={ci}
                                            onClick={(e) => { e.stopPropagation(); setSelSi(si); setActive({ si, ci }); }}
                                            className={`rounded-lg border border-dashed p-2 transition-colors ${active?.si === si && active?.ci === ci ? 'border-foreground/50 bg-muted/40' : 'border-border'}`}
                                        >
                                            <div className="grid gap-2">
                                                {col.blocks.map((block, bi) => (
                                                    <div key={block.id} onClick={(e) => e.stopPropagation()}>
                                                        <BlockEditor
                                                            block={block}
                                                            first={bi === 0}
                                                            last={bi === col.blocks.length - 1}
                                                            onPatch={(patch) => mutate((d) => { Object.assign(d[si].columns[ci].blocks[bi], patch); })}
                                                            onMove={(dir) => mutate((d) => { const arr = d[si].columns[ci].blocks; const j = bi + dir; if (j >= 0 && j < arr.length) [arr[bi], arr[j]] = [arr[j], arr[bi]]; })}
                                                            onDup={() => mutate((d) => { const c = clone(d[si].columns[ci].blocks[bi]); c.id = uid(); d[si].columns[ci].blocks.splice(bi + 1, 0, c); })}
                                                            onDel={() => mutate((d) => d[si].columns[ci].blocks.splice(bi, 1))}
                                                        />
                                                    </div>
                                                ))}
                                            </div>
                                            <button type="button" onClick={(e) => { e.stopPropagation(); setSelSi(si); setActive({ si, ci }); setTab('add'); setPanelOpen(true); }} className="mt-2 flex w-full items-center justify-center gap-1.5 rounded-md border border-dashed border-border py-2 text-xs font-medium text-muted-foreground hover:border-foreground/40 hover:text-foreground">
                                                <Plus className="size-3.5" /> Add widget here
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                        <div className="flex justify-center">
                            <Button type="button" variant="outline" onClick={() => addSection(1)}><Plus className="size-4" /> Add section</Button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
