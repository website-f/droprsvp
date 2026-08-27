import { contentClass } from '@/components/rich-editor';

export type PageBlock =
    | { id: string; type: 'richtext'; html: string }
    | { id: string; type: 'image'; url: string; alt: string };
export type PageColumn = { blocks: PageBlock[] };
export type PageSection = { id: string; title: string; columns: PageColumn[] };

function BlockView({ block }: { block: PageBlock }) {
    if (block.type === 'image') {
        if (!block.url) return null;
        return (
            <figure className="m-0">
                <img src={block.url} alt={block.alt} loading="lazy" className="w-full rounded-xl border border-border" />
                {block.alt && <figcaption className="mt-2 text-center text-xs text-muted-foreground">{block.alt}</figcaption>}
            </figure>
        );
    }
    return <div className={contentClass} dangerouslySetInnerHTML={{ __html: block.html || '' }} />;
}

/**
 * Read-only renderer for a page's section/column layout. Shared by the public
 * page and the builder's live preview so "what you build is what ships".
 * Two-column sections collapse to a single column on mobile.
 */
export function PageSections({ sections }: { sections: PageSection[] }) {
    return (
        <div className="grid gap-12">
            {sections.map((s) => (
                <section key={s.id}>
                    {s.title && <h2 className="mb-6 text-2xl font-bold tracking-tight sm:text-3xl">{s.title}</h2>}
                    <div className={s.columns.length === 2 ? 'grid gap-8 md:grid-cols-2' : 'grid gap-8'}>
                        {s.columns.map((col, ci) => (
                            <div key={ci} className="grid content-start gap-5">
                                {col.blocks.map((b) => <BlockView key={b.id} block={b} />)}
                            </div>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}

/** True when a layout actually has renderable content (used to choose layout vs legacy body). */
export function hasSections(sections?: PageSection[] | null): boolean {
    return !!sections && sections.some((s) => s.title || s.columns.some((c) => c.blocks.length > 0));
}
