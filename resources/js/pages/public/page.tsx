
import { Render  } from '@measured/puck';
import type {Data} from '@measured/puck';
import { PageSections, hasSections  } from '@/components/cms/page-sections';
import type {PageSection} from '@/components/cms/page-sections';
import { renderConfig } from '@/components/cms/puck-render';
import type {PostCard} from '@/components/cms/puck-render';
import { HtmlContent, resolveLayout } from '@/components/html-content';
import type { HtmlLayoutChoice } from '@/components/html-content';
import { PageTitle } from '@/components/page-title';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { contentClass } from '@/components/rich-editor';

interface Seo { title: string }
interface Page { title: string; body: string | null; layout: PageSection[] | null; puck: Data | null; editor?: string; html?: string | null; html_layout?: HtmlLayoutChoice | null; posts?: PostCard[] }

export default function PublicPage({ page, preview }: { page: Page; seo: Seo; preview?: boolean }) {
    const built = !!(page.puck && Array.isArray(page.puck.content) && page.puck.content.length > 0);

    return (
        <>
            {/* SEO is server-rendered by Laravel; keep only the tab <title>. */}
            <PageTitle />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                {preview && (
                    <div className="bg-amber-500 px-4 py-1.5 text-center text-xs font-medium text-black">
                        Preview — this is a draft. Only you can see it.
                    </div>
                )}
                <PublicHeader />

                {page.editor === 'html' && page.html ? (
                    // Pasted HTML, between the site's own header and footer.
                    // Partial HTML takes the site's typography in a readable
                    // column; a full page keeps its own design, full width.
                    resolveLayout(page.html, page.html_layout) === 'content' ? (
                        <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-12">
                            <HtmlContent html={page.html} layout="content" />
                        </main>
                    ) : (
                        <main className="flex-1">
                            <HtmlContent html={page.html} layout="full" />
                        </main>
                    )
                ) : built ? (
                    // Puck page: rendered from structured data with the same widgets as the editor.
                    <main className="flex-1">
                        <Render config={renderConfig} data={page.puck!} metadata={{ posts: page.posts ?? [] }} />
                    </main>
                ) : (
                    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-12">
                        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{page.title}</h1>
                        {hasSections(page.layout)
                            ? <div className="mt-8"><PageSections sections={page.layout!} /></div>
                            : <article className={`mt-6 ${contentClass}`} dangerouslySetInnerHTML={{ __html: page.body ?? '' }} />}
                    </main>
                )}

                <PublicFooter />
            </div>
        </>
    );
}
