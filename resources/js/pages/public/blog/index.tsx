import { Head, Link } from '@inertiajs/react';
import {   BlogSidebar, categoryUrl, postUrl } from '@/components/blog-sidebar';
import type {BlogPostCard, BlogSidebarData} from '@/components/blog-sidebar';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Paginated { data: BlogPostCard[]; prev_page_url: string | null; next_page_url: string | null }
interface Seo { title: string }

export default function BlogIndex({ posts, sidebar, activeCategory, seo }: {
    posts: Paginated;
    sidebar: BlogSidebarData;
    activeCategory: string | null;
    seo: Seo;
}) {
    const active = sidebar.categories.find((c) => c.slug === activeCategory);

    return (
        <>
            {/* SEO is server-rendered by Laravel; keep only the tab <title>. */}
            <Head title={seo.title} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6 sm:py-12">
                    <header>
                        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{active ? active.name : 'Blog'}</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {active ? `${active.count} ${active.count === 1 ? 'post' : 'posts'} in this category.` : 'Guides, tips and stories for hosts and event-goers.'}
                        </p>
                    </header>

                    {/* Category pills — the switcher on phones, where the rail is far below. */}
                    {sidebar.categories.length > 0 && (
                        <div className="mt-6 flex flex-wrap gap-2 lg:hidden">
                            <Link href={categoryUrl(null)} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${!activeCategory ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>All</Link>
                            {sidebar.categories.map((c) => (
                                <Link key={c.slug} href={categoryUrl(c.slug)} className={`rounded-full border px-4 py-1.5 text-sm transition-colors ${activeCategory === c.slug ? 'border-foreground bg-foreground text-background' : 'border-border hover:border-foreground/40'}`}>
                                    {c.name}
                                </Link>
                            ))}
                        </div>
                    )}

                    <div className="mt-8 grid gap-10 lg:grid-cols-[minmax(0,1fr)_19rem] lg:gap-12">
                        <div className="min-w-0">
                            {posts.data.length === 0 ? (
                                <p className="rounded-2xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground">
                                    No posts here yet.
                                </p>
                            ) : (
                                <div className="grid gap-6 sm:grid-cols-2">
                                    {posts.data.map((p) => (
                                        <Link key={p.slug} href={postUrl(p.slug)} className="group flex flex-col overflow-hidden rounded-2xl border border-border bg-card transition-all hover:-translate-y-0.5 hover:border-foreground/30 hover:shadow-md">
                                            {p.cover_image
                                                ? <img src={p.cover_image} alt={p.title} className="aspect-[16/9] w-full object-cover" />
                                                : <span className="aspect-[16/9] w-full bg-muted" />}
                                            <div className="flex flex-1 flex-col p-5">
                                                <div className="mb-2 flex flex-wrap items-center gap-2">
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
                        </div>

                        <BlogSidebar sidebar={sidebar} activeCategory={activeCategory} />
                    </div>
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
