import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PublicFooter, PublicHeader } from '@/components/public-header';

interface PostCard { title: string; slug: string; excerpt: string | null; cover_image: string | null; category: string | null; date: string | null }
interface Paginated { data: PostCard[]; prev_page_url: string | null; next_page_url: string | null }
interface Seo { title: string }

export default function BlogIndex({ posts, seo }: { posts: Paginated; seo: Seo }) {
    return (
        <>
            {/* SEO is server-rendered by Laravel; keep only the tab <title>. */}
            <Head title={seo.title} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                <main className="mx-auto w-full max-w-4xl flex-1 px-6 py-12">
                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">Blog</h1>

                    {posts.data.length === 0 ? (
                        <p className="mt-8 text-sm text-muted-foreground">No posts yet.</p>
                    ) : (
                        <div className="mt-8 grid gap-6 sm:grid-cols-2">
                            {posts.data.map((p) => (
                                <Link key={p.slug} href={`/blog/${p.slug}`} className="group overflow-hidden rounded-2xl border border-border bg-card shadow-sm transition-colors hover:border-foreground/30">
                                    {p.cover_image && <img src={p.cover_image} alt={p.title} className="aspect-[16/9] w-full object-cover" />}
                                    <div className="p-5">
                                        <div className="mb-2 flex items-center gap-2">
                                            {p.category && <Badge variant="secondary">{p.category}</Badge>}
                                            {p.date && <span className="text-xs text-muted-foreground">{p.date}</span>}
                                        </div>
                                        <h2 className="text-lg font-semibold leading-snug group-hover:underline">{p.title}</h2>
                                        {p.excerpt && <p className="mt-2 line-clamp-3 text-sm text-muted-foreground">{p.excerpt}</p>}
                                    </div>
                                </Link>
                            ))}
                        </div>
                    )}

                    {(posts.prev_page_url || posts.next_page_url) && (
                        <div className="mt-10 flex justify-between">
                            <Button asChild variant="outline" disabled={!posts.prev_page_url}>
                                {posts.prev_page_url ? <Link href={posts.prev_page_url}>← Newer</Link> : <span>← Newer</span>}
                            </Button>
                            <Button asChild variant="outline" disabled={!posts.next_page_url}>
                                {posts.next_page_url ? <Link href={posts.next_page_url}>Older →</Link> : <span>Older →</span>}
                            </Button>
                        </div>
                    )}
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
