import { Link } from '@inertiajs/react';
import { ChevronDown, Clock, FolderOpen, LayoutList, Newspaper } from 'lucide-react';
import { useState } from 'react';

export interface BlogPostCard {
    title: string;
    slug: string;
    excerpt: string | null;
    cover_image: string | null;
    category: string | null;
    category_slug?: string | null;
    date: string | null;
}
export interface BlogCategory { name: string; slug: string; count: number }
export interface BlogAd { enabled: boolean; title: string; image: string; url: string; caption: string }
export interface BlogSidebarData { categories: BlogCategory[]; recent: BlogPostCard[]; related: BlogPostCard[]; ad: BlogAd }
export interface TocItem { id: string; text: string; level: number }

export const postUrl = (slug: string) => `/en-my/blog/${slug}/`;
export const categoryUrl = (slug: string | null) => (slug ? `/en-my/blog/?category=${slug}` : '/en-my/blog/');

/** A contents list is only worth showing once there's something to navigate. */
export const hasContents = (toc?: TocItem[]) => !!toc && toc.length > 1;

function Widget({ icon: Icon, title, children }: { icon: typeof Clock; title: string; children: React.ReactNode }) {
    return (
        <section className="rounded-2xl border border-border bg-card">
            <h2 className="flex items-center gap-2 border-b border-border px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                <Icon className="size-3.5" /> {title}
            </h2>
            <div className="p-5">{children}</div>
        </section>
    );
}

/**
 * Collapsible table of contents, shown at the top of the article itself (above
 * the body) — not in the rail. The links are real anchors, so they work for
 * crawlers and with JavaScript off; the click handler only adds smooth scrolling.
 */
export function TableOfContentsCard({ items, defaultOpen = true, className = '' }: {
    items: TocItem[];
    defaultOpen?: boolean;
    className?: string;
}) {
    const [open, setOpen] = useState(defaultOpen);

    if (items.length === 0) {
        return null;
    }

    const jump = (e: React.MouseEvent, id: string) => {
        const target = document.getElementById(id);

        if (!target) {
            return; // let the browser follow the anchor
        }

        e.preventDefault();
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
        history.replaceState(null, '', `#${id}`);
    };

    return (
        <section className={`overflow-hidden rounded-xl border border-border bg-muted/30 ${className}`}>
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                className="flex w-full items-center gap-2 px-4 py-3 text-left transition-colors hover:bg-muted/60 sm:px-5"
            >
                <LayoutList className="size-4 shrink-0 text-muted-foreground" />
                <span className="flex-1 text-sm font-semibold">Table of Contents</span>
                <span className="rounded-full bg-background px-2 py-0.5 text-[11px] font-medium tabular-nums text-muted-foreground">{items.length}</span>
                <ChevronDown className={`size-4 shrink-0 text-muted-foreground transition-transform duration-200 ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <nav aria-label="Table of contents" className="border-t border-border px-4 py-3 sm:px-5">
                    <ol className="space-y-1 text-sm">
                        {items.map((h) => (
                            <li key={h.id} className={h.level === 3 ? 'pl-5' : ''}>
                                <a
                                    href={`#${h.id}`}
                                    onClick={(e) => jump(e, h.id)}
                                    className="block py-1 text-muted-foreground transition-colors hover:text-foreground hover:underline"
                                >
                                    {h.text}
                                </a>
                            </li>
                        ))}
                    </ol>
                </nav>
            )}
        </section>
    );
}

/** The promo slot, only rendered once an admin has switched it on with an image. */
function AdSlot({ ad }: { ad: BlogAd }) {
    if (!ad.enabled || !ad.image) {
        return null;
    }

    const art = <img src={ad.image} alt={ad.title || 'Advertisement'} className="w-full rounded-xl border border-border object-cover" />;

    return (
        <section className="rounded-2xl border border-border bg-card">
            {ad.title && (
                <h2 className="border-b border-border px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{ad.title}</h2>
            )}
            <div className="p-5">
                {ad.url
                    ? <a href={ad.url} target="_blank" rel="noopener sponsored" className="block transition-opacity hover:opacity-90">{art}</a>
                    : art}
                {ad.caption && <p className="mt-3 text-xs leading-relaxed text-muted-foreground">{ad.caption}</p>}
            </div>
        </section>
    );
}

/** A compact thumbnail + title row, used by both post lists in the rail. */
function PostRow({ post }: { post: BlogPostCard }) {
    return (
        <li>
            <Link href={postUrl(post.slug)} className="group flex gap-3">
                {post.cover_image
                    ? <img src={post.cover_image} alt="" loading="lazy" className="size-14 shrink-0 rounded-lg border border-border object-cover" />
                    : <span className="size-14 shrink-0 rounded-lg border border-border bg-muted" />}
                <span className="min-w-0">
                    <span className="line-clamp-2 text-sm font-medium leading-snug group-hover:underline">{post.title}</span>
                    {post.date && <span className="mt-1 block text-xs text-muted-foreground">{post.date}</span>}
                </span>
            </Link>
        </li>
    );
}

/**
 * The blog's right-hand rail: the promo slot, then categories, related and
 * recent posts. The table of contents deliberately lives at the top of the
 * article instead — see TableOfContentsCard.
 *
 * The rail sticks beside the article on desktop and scrolls internally when
 * it's taller than the screen; on mobile it flows under the article.
 */
export function BlogSidebar({ sidebar, activeCategory }: {
    sidebar: BlogSidebarData;
    activeCategory?: string | null;
}) {
    const related = sidebar.related ?? [];
    const total = sidebar.categories.reduce((n, c) => n + c.count, 0);

    return (
        <aside className="min-w-0">
            <div className="space-y-5 lg:sticky lg:top-24 lg:max-h-[calc(100vh-7rem)] lg:overflow-y-auto lg:pr-1 lg:[scrollbar-width:thin]">
                <AdSlot ad={sidebar.ad} />

                {sidebar.categories.length > 0 && (
                    <Widget icon={FolderOpen} title="Categories">
                        <ul className="space-y-1">
                            <li>
                                <Link
                                    href={categoryUrl(null)}
                                    className={`flex items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors ${!activeCategory ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                                >
                                    <span>All posts</span>
                                    <span className="text-xs tabular-nums opacity-70">{total}</span>
                                </Link>
                            </li>
                            {sidebar.categories.map((c) => (
                                <li key={c.slug}>
                                    <Link
                                        href={categoryUrl(c.slug)}
                                        className={`flex items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors ${activeCategory === c.slug ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                                    >
                                        <span className="truncate">{c.name}</span>
                                        <span className="ml-2 shrink-0 text-xs tabular-nums opacity-70">{c.count}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Widget>
                )}

                {related.length > 0 && (
                    <Widget icon={Newspaper} title="Related posts">
                        <ul className="space-y-4">
                            {related.map((p) => <PostRow key={p.slug} post={p} />)}
                        </ul>
                    </Widget>
                )}

                {sidebar.recent.length > 0 && (
                    <Widget icon={Clock} title="Recent posts">
                        <ul className="space-y-4">
                            {sidebar.recent.map((p) => <PostRow key={p.slug} post={p} />)}
                        </ul>
                    </Widget>
                )}
            </div>
        </aside>
    );
}
