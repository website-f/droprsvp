import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Clock, User } from 'lucide-react';
import {  BlogSidebar, categoryUrl  } from '@/components/blog-sidebar';
import type {BlogSidebarData, TocItem} from '@/components/blog-sidebar';
import { PublicFooter, PublicHeader } from '@/components/public-header';
import { contentClass } from '@/components/rich-editor';
import { Badge } from '@/components/ui/badge';

interface PostView {
    title: string;
    body: string | null;
    cover_image: string | null;
    category: string | null;
    category_slug?: string | null;
    author: string | null;
    date: string | null;
    reading_minutes: number;
}
interface Seo { title: string }

export default function BlogShow({ post, toc, sidebar, seo }: { post: PostView; toc: TocItem[]; sidebar: BlogSidebarData; seo: Seo }) {
    const meta = [
        post.author && { icon: User, text: post.author },
        post.date && { icon: CalendarDays, text: post.date },
        { icon: Clock, text: `${post.reading_minutes} min read` },
    ].filter(Boolean) as { icon: typeof User; text: string }[];

    return (
        <>
            {/* SEO is server-rendered by Laravel; keep only the tab <title>. */}
            <Head title={seo.title} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <PublicHeader />

                <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6 sm:py-12">
                    <Link href="/en-my/blog/" className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground">
                        <ArrowLeft className="size-4" /> All posts
                    </Link>

                    <div className="mt-6 grid gap-10 lg:grid-cols-[minmax(0,1fr)_19rem] lg:gap-12">
                        <article className="min-w-0">
                            <header>
                                {post.category && (
                                    <Link href={categoryUrl(post.category_slug ?? null)}>
                                        <Badge variant="secondary" className="hover:bg-secondary/70">{post.category}</Badge>
                                    </Link>
                                )}
                                <h1 className="mt-3 text-pretty text-3xl font-bold leading-tight tracking-tight sm:text-4xl">{post.title}</h1>
                                <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-muted-foreground">
                                    {meta.map((m, i) => (
                                        <span key={i} className="inline-flex items-center gap-1.5"><m.icon className="size-3.5" /> {m.text}</span>
                                    ))}
                                </div>
                            </header>

                            {post.cover_image && (
                                <img src={post.cover_image} alt={post.title} className="mt-7 aspect-[16/8] w-full rounded-2xl border border-border object-cover" />
                            )}

                            {/* Body. Any [data-toc] block the author inserted has already
                                been swapped for the real contents list server-side. */}
                            <div className={`mt-8 ${contentClass}`} dangerouslySetInnerHTML={{ __html: post.body ?? '' }} />
                        </article>

                        <BlogSidebar sidebar={sidebar} toc={toc} />
                    </div>
                </main>

                <PublicFooter />
            </div>
        </>
    );
}
