import type { CSSProperties } from 'react';
import { contentClass } from '@/components/rich-editor';

/* ----------------------------------------------------------------- schema */

export type Align = 'left' | 'center' | 'right';

export interface SectionSettings {
    cols: number;                 // 1..4
    gap: 'sm' | 'md' | 'lg';
    padY: 'none' | 'sm' | 'md' | 'lg' | 'xl';
    width: 'boxed' | 'wide' | 'full';
    align: Align;
    valign: 'top' | 'center' | 'bottom';
    bg: string;                   // '' or hex
    borderW?: number;             // px border width (0 = none)
    borderColor?: string;         // hex
    radius?: number;              // px corner radius
    colWidths?: string[];         // ratio per column, e.g. ['1','2']
}

export type PageBlock =
    | { id: string; type: 'heading'; text: string; level: 2 | 3 | 4; align: Align }
    | { id: string; type: 'richtext'; html: string }
    | { id: string; type: 'image'; url: string; alt: string; rounded: boolean }
    | { id: string; type: 'button'; label: string; url: string; variant: 'primary' | 'outline'; align: Align; newTab: boolean }
    | { id: string; type: 'divider' }
    | { id: string; type: 'spacer'; size: 'sm' | 'md' | 'lg' | 'xl' }
    | { id: string; type: 'video'; url: string };

export interface PageColumn { blocks: PageBlock[]; width?: string }
export interface PageSection { id: string; title?: string; columns: PageColumn[]; settings: SectionSettings }

export const DEFAULT_SETTINGS: SectionSettings = {
    cols: 1, gap: 'md', padY: 'md', width: 'boxed', align: 'left', valign: 'top', bg: '',
    borderW: 0, borderColor: '#e5e5e6', radius: 0,
};

/* ------------------------------------------------------------- style maps */

const PAD_Y: Record<SectionSettings['padY'], string> = {
    none: 'py-0', sm: 'py-4', md: 'py-8', lg: 'py-14', xl: 'py-20',
};
const GAP: Record<SectionSettings['gap'], string> = { sm: 'gap-3', md: 'gap-6', lg: 'gap-10' };
const WIDTH: Record<SectionSettings['width'], string> = {
    boxed: 'max-w-3xl', wide: 'max-w-6xl', full: 'max-w-none',
};
const ALIGN: Record<Align, string> = { left: 'text-left', center: 'text-center', right: 'text-right' };
const VALIGN: Record<SectionSettings['valign'], string> = { top: 'items-start', center: 'items-center', bottom: 'items-end' };
const SPACER: Record<'sm' | 'md' | 'lg' | 'xl', string> = { sm: 'h-4', md: 'h-8', lg: 'h-16', xl: 'h-28' };

function colTemplate(s: SectionSettings): string {
    const n = Math.max(1, s.cols);
    if (n === 1) return '1fr';
    if (s.colWidths && s.colWidths.length === n) return s.colWidths.map((w) => `${w}fr`).join(' ');
    return `repeat(${n}, 1fr)`;
}

/** Turn a YouTube/Vimeo/any URL into an embeddable src. */
function embedSrc(url: string): string | null {
    if (!url) return null;
    const yt = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([\w-]{11})/);
    if (yt) return `https://www.youtube.com/embed/${yt[1]}`;
    const vim = url.match(/vimeo\.com\/(\d+)/);
    if (vim) return `https://player.vimeo.com/video/${vim[1]}`;
    return url; // assume already an embed URL
}

/* --------------------------------------------------------------- blocks */

function BlockView({ block, align }: { block: PageBlock; align: Align }) {
    switch (block.type) {
        case 'heading': {
            const cls = `${ALIGN[block.align]} font-bold tracking-tight ${block.level === 2 ? 'text-2xl sm:text-3xl' : block.level === 3 ? 'text-xl sm:text-2xl' : 'text-lg sm:text-xl'}`;
            if (block.level === 2) return <h2 className={cls}>{block.text}</h2>;
            if (block.level === 3) return <h3 className={cls}>{block.text}</h3>;
            return <h4 className={cls}>{block.text}</h4>;
        }
        case 'richtext':
            return <div className={contentClass} dangerouslySetInnerHTML={{ __html: block.html || '' }} />;
        case 'image':
            return block.url
                ? <img src={block.url} alt={block.alt} loading="lazy" className={`h-auto w-full border border-border ${block.rounded ? 'rounded-xl' : ''}`} />
                : null;
        case 'button':
            return (
                <div className={ALIGN[block.align]}>
                    <a
                        href={block.url || '#'}
                        {...(block.newTab ? { target: '_blank', rel: 'noopener' } : {})}
                        className={`inline-flex items-center justify-center rounded-full px-6 py-2.5 text-sm font-semibold transition-colors ${block.variant === 'outline' ? 'border border-foreground text-foreground hover:bg-accent' : 'bg-primary text-primary-foreground hover:opacity-90'}`}
                    >
                        {block.label || 'Button'}
                    </a>
                </div>
            );
        case 'divider':
            return <hr className="border-border" />;
        case 'spacer':
            return <div className={SPACER[block.size]} aria-hidden />;
        case 'video': {
            const src = embedSrc(block.url);
            return src
                ? <div className="aspect-video w-full overflow-hidden rounded-xl border border-border"><iframe src={src} title="Video" className="size-full" allowFullScreen loading="lazy" /></div>
                : null;
        }
        default:
            return null;
    }
    void align;
}

/* -------------------------------------------------------------- section */

/** Read-only renderer shared by the public page and the builder preview. */
export function PageSections({ sections }: { sections: PageSection[] }) {
    return (
        <div className="grid gap-0">
            {sections.map((s) => {
                const set = { ...DEFAULT_SETTINGS, ...s.settings };
                const outerStyle: CSSProperties = {
                    backgroundColor: set.bg || undefined,
                    borderWidth: set.borderW ? `${set.borderW}px` : undefined,
                    borderStyle: set.borderW ? 'solid' : undefined,
                    borderColor: set.borderW ? set.borderColor : undefined,
                    borderRadius: set.radius ? `${set.radius}px` : undefined,
                };
                const gridStyle = { '--drsvp-cols': colTemplate(set) } as CSSProperties;
                return (
                    <section key={s.id} className={PAD_Y[set.padY]} style={outerStyle}>
                        <div className={`mx-auto w-full px-4 sm:px-6 ${WIDTH[set.width]} ${ALIGN[set.align]}`}>
                            {s.title && <h2 className="mb-6 text-2xl font-bold tracking-tight sm:text-3xl">{s.title}</h2>}
                            <div className={`drsvp-cols ${GAP[set.gap]} ${VALIGN[set.valign]}`} style={gridStyle}>
                                {s.columns.map((col, ci) => (
                                    <div key={ci} className="grid content-start gap-5">
                                        {col.blocks.map((b) => <BlockView key={b.id} block={b} align={set.align} />)}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>
                );
            })}
        </div>
    );
}

/** True when a layout actually has renderable content. */
export function hasSections(sections?: PageSection[] | null): boolean {
    return !!sections && sections.some((s) => s.title || s.columns.some((c) => c.blocks.length > 0));
}
