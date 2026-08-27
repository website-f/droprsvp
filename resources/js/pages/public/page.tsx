import { Head, Link } from '@inertiajs/react';
import { contentClass } from '@/components/rich-editor';

interface Seo { title: string; description: string; canonical: string; og_image: string | null; robots: string }

export default function PublicPage({ page, seo, schema }: { page: { title: string; body: string | null }; seo: Seo; schema: Record<string, unknown> }) {
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

            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b border-border">
                    <div className="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                        <Link href="/" className="text-xl font-bold tracking-tight">Drop<span className="text-muted-foreground">RSVP</span></Link>
                        <Link href="/" className="text-sm text-muted-foreground hover:text-foreground">Home</Link>
                    </div>
                </header>

                <main className="mx-auto max-w-3xl px-6 py-12">
                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{page.title}</h1>
                    <article className={`mt-6 ${contentClass}`} dangerouslySetInnerHTML={{ __html: page.body ?? '' }} />
                </main>

                <footer className="border-t border-border">
                    <div className="mx-auto max-w-3xl px-6 py-8 text-sm text-muted-foreground">&copy; {new Date().getFullYear()} DropRSVP</div>
                </footer>
            </div>
        </>
    );
}
