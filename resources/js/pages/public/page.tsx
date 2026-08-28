import { Head } from '@inertiajs/react';
import { contentClass } from '@/components/rich-editor';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { PageSections, hasSections, type PageSection } from '@/components/cms/page-sections';

interface Seo { title: string }
interface Page { title: string; body: string | null; layout: PageSection[] | null; css: string | null }

export default function PublicPage({ page, seo }: { page: Page; seo: Seo }) {
    const builtWithBuilder = !!page.css;

    return (
        <>
            {/* SEO is server-rendered by Laravel; keep only the tab <title>. */}
            <Head title={seo.title} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                {builtWithBuilder ? (
                    // GrapesJS page: its own HTML + CSS, rendered full-bleed.
                    <main className="flex-1">
                        <style dangerouslySetInnerHTML={{ __html: page.css ?? '' }} />
                        <div dangerouslySetInnerHTML={{ __html: page.body ?? '' }} />
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
