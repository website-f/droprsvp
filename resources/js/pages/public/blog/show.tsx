import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { contentClass } from '@/components/rich-editor';

interface PostView { title: string; body: string | null; cover_image: string | null; category: string | null; author: string | null; date: string | null }
interface Seo { title: string; description: string; canonical: string; og_image: string | null; robots: string }

export default function BlogShow({ post, seo, schema }: { post: PostView; seo: Seo; schema: Record<string, unknown> }) {
    return (
        <>
            <Head title={seo.title}>
                <meta name="description" content={seo.description} head-key="description" />
                <meta name="robots" content={seo.robots} head-key="robots" />
                <link rel="canonical" href={seo.canonical} head-key="canonical" />
                <meta property="og:title" content={seo.title} head-key="ogtitle" />
                <meta property="og:description" content={seo.description} head-key="ogdesc" />
                <meta property="og:type" content="article" head-key="ogtype" />
                <meta property="og:url" content={seo.canonical} head-key="ogurl" />
                {seo.og_image && <meta property="og:image" content={seo.og_image} head-key="ogimage" />}
                <meta name="twitter:card" content="summary_large_image" head-key="twcard" />
            </Head>
            <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(schema) }} />

            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b border-border">
                    <div className="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                        <Link href="/" className="text-xl font-bold tracking-tight">Drop<span className="text-muted-foreground">RSVP</span></Link>
                        <Link href="/blog" className="text-sm text-muted-foreground hover:text-foreground">All posts</Link>
                    </div>
                </header>

                <main className="mx-auto max-w-3xl px-6 py-12">
                    <div className="mb-3 flex items-center gap-2">
                        {post.category && <Badge variant="secondary">{post.category}</Badge>}
                        <span className="text-xs text-muted-foreground">{[post.author, post.date].filter(Boolean).join(' · ')}</span>
                    </div>
                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{post.title}</h1>
                    {post.cover_image && <img src={post.cover_image} alt={post.title} className="mt-6 aspect-[16/8] w-full rounded-2xl object-cover" />}
                    <article className={`mt-8 ${contentClass}`} dangerouslySetInnerHTML={{ __html: post.body ?? '' }} />
                </main>

                <footer className="border-t border-border">
                    <div className="mx-auto max-w-3xl px-6 py-8 text-sm text-muted-foreground">&copy; {new Date().getFullYear()} DropRSVP</div>
                </footer>
            </div>
        </>
    );
}
