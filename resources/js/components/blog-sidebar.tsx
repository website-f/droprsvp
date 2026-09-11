import { Link } from '@inertiajs/react';
import { Clock, FolderOpen, ListTree } from 'lucide-react';

export interface BlogPostCard {
    title: string;
    slug: string;
    excerpt: string | null;
    cover_image: string | null;
    category: string | null;
    category_slug?: string | null;
    date: string | null;
}
export interface BlogCategory {
    name: string;
    slug: string;
    count: number;
}
export interface BlogAd {
    enabled: boolean;
    title: string;
    image: string;
    url: string;
    caption: string;
}
export interface BlogSidebarData {
    categories: BlogCategory[];
    recent: BlogPostCard[];
    ad: BlogAd;
}
export interface TocItem {
    id: string;
    text: string;
    level: number;
}

export const postUrl = (slug: string) => `/en-my/blog/${slug}/`;
export const categoryUrl = (slug: string | null) =>
    slug ? `/en-my/blog/?category=${slug}` : '/en-my/blog/';

function Widget({
    icon: Icon,
    title,
    children,
}: {
    icon?: typeof Clock;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-2xl border border-border bg-card p-5">
            <h2 className="flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                {Icon && <Icon className="size-3.5" />} {title}
            </h2>
            <div className="mt-4">{children}</div>
        </section>
    );
}

/** The promo slot, only rendered once an admin has switched it on with an image. */
function AdSlot({ ad }: { ad: BlogAd }) {
    if (!ad.enabled || !ad.image) {
        return null;
    }

    const art = (
        <img
            src={ad.image}
            alt={ad.title || 'Advertisement'}
            className="w-full rounded-xl border border-border object-cover"
        />
    );

    return (
        <section className="rounded-2xl border border-border bg-card p-5">
            {ad.title && (
                <h2 className="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                    {ad.title}
                </h2>
            )}
            {ad.url ? (
                <a
                    href={ad.url}
                    target="_blank"
                    rel="noopener sponsored"
                    className="block transition-opacity hover:opacity-90"
                >
                    {art}
                </a>
            ) : (
                art
            )}
            {ad.caption && (
                <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                    {ad.caption}
                </p>
            )}
        </section>
    );
}

/**
 * The blog's right-hand rail: contents (article pages only), category switcher,
 * recent posts and the promo slot. Sticky on desktop; on mobile it simply flows
 * underneath the article, so nothing is hidden behind a toggle.
 */
export function BlogSidebar({
    sidebar,
    toc,
    activeCategory,
}: {
    sidebar: BlogSidebarData;
    toc?: TocItem[];
    activeCategory?: string | null;
}) {
    return (
        <aside className="min-w-0 space-y-5 lg:sticky lg:top-24 lg:h-fit">
            {/* Contents — desktop only: the article already carries an inline
                contents block for phones, so this would just repeat it. */}
            {toc && toc.length > 1 && (
                <div className="hidden lg:block">
                    <Widget icon={ListTree} title="On this page">
                        <ol className="space-y-2 text-sm">
                            {toc.map((h) => (
                                <li
                                    key={h.id}
                                    className={h.level === 3 ? 'pl-4' : ''}
                                >
                                    <a
                                        href={`#${h.id}`}
                                        className="text-muted-foreground transition-colors hover:text-foreground hover:underline"
                                    >
                                        {h.text}
                                    </a>
                                </li>
                            ))}
                        </ol>
                    </Widget>
                </div>
            )}

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
                                <span className="text-xs tabular-nums opacity-70">
                                    {sidebar.categories.reduce(
                                        (n, c) => n + c.count,
                                        0,
                                    )}
                                </span>
                            </Link>
                        </li>
                        {sidebar.categories.map((c) => (
                            <li key={c.slug}>
                                <Link
                                    href={categoryUrl(c.slug)}
                                    className={`flex items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors ${activeCategory === c.slug ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                                >
                                    <span className="truncate">{c.name}</span>
                                    <span className="ml-2 shrink-0 text-xs tabular-nums opacity-70">
                                        {c.count}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Widget>
            )}

            {sidebar.recent.length > 0 && (
                <Widget icon={Clock} title="Recent posts">
                    <ul className="space-y-4">
                        {sidebar.recent.map((p) => (
                            <li key={p.slug}>
                                <Link
                                    href={postUrl(p.slug)}
                                    className="group flex gap-3"
                                >
                                    {p.cover_image ? (
                                        <img
                                            src={p.cover_image}
                                            alt=""
                                            className="size-14 shrink-0 rounded-lg border border-border object-cover"
                                        />
                                    ) : (
                                        <span className="size-14 shrink-0 rounded-lg border border-border bg-muted" />
                                    )}
                                    <span className="min-w-0">
                                        <span className="line-clamp-2 text-sm leading-snug font-medium group-hover:underline">
                                            {p.title}
                                        </span>
                                        {p.date && (
                                            <span className="mt-1 block text-xs text-muted-foreground">
                                                {p.date}
                                            </span>
                                        )}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Widget>
            )}
        </aside>
    );
}
