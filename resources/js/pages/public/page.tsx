import { Head } from '@inertiajs/react';
import { contentClass } from '@/components/rich-editor';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { PageSections, hasSections, type PageSection } from '@/components/cms/page-sections';

interface Seo { title: string; description: string; canonical: string; og_image: string | null; robots: string }

export default function PublicPage({ page, seo, schema }: { page: { title: string; body: string | null; layout: PageSection[] | null }; seo: Seo; schema: Record<string, unknown> }) {
    return (
        <>
            <Head title={seo.title}>
                <meta name="description" content={seo.description} head-key="description" />
                <meta name="robots" content={seo.robots} head-key="robots" />
                <link rel="canonical" href={seo.canonical} head-key="canonical" />
                <meta property="og:title" content={seo.title} head-key="ogtitle" />
                <meta property="og:description" content={seo.description} head-key="ogdesc" />
                <meta property="og:type" content="website" head-key="ogtype" />
                <meta property="og:url" content={seo.canonical} head-key="ogurl" />
                {seo.og_image && <meta property="og:image" content={seo.og_image} head-key="ogimage" />}
            </Head>
            <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(schema) }} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-12">
                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{page.title}</h1>
                    {hasSections(page.layout)
                        ? <div className="mt-8"><PageSections sections={page.layout!} /></div>
                        : <article className={`mt-6 ${contentClass}`} dangerouslySetInnerHTML={{ __html: page.body ?? '' }} />}
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
