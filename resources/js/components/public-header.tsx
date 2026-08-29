import { Link, usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useState } from 'react';
import { AppearanceToggle } from '@/components/appearance-toggle';
import { Wordmark } from '@/components/brand';
import { Footer } from '@/components/cms/footer-blocks';
import type { FooterData } from '@/components/cms/footer-blocks';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';
import type { PublicNavItem } from '@/types';

function NavLink({ item, onClick }: { item: PublicNavItem; onClick?: () => void }) {
    const cls = 'text-sm font-medium text-foreground/70 transition-colors hover:text-foreground';
    const isInternal = item.url.startsWith('/') && !item.new_tab;

    return isInternal ? (
        <Link href={item.url} className={cls} onClick={onClick}>{item.label}</Link>
    ) : (
        <a href={item.url} className={cls} onClick={onClick} {...(item.new_tab ? { target: '_blank', rel: 'noopener' } : {})}>{item.label}</a>
    );
}

/**
 * Shared chrome for every public, server-rendered page. The nav links come from
 * the cached `nav` shared prop (managed under Admin → Menu), so a page added to
 * the menu shows up here across the whole site.
 */
export function PublicHeader() {
    const { auth, nav, branding } = usePage().props;
    const items = (nav ?? []) as PublicNavItem[];
    const [open, setOpen] = useState(false);

    return (
        <header className="sticky top-0 z-30 border-b border-border bg-background/85 backdrop-blur">
            <div className="mx-auto flex max-w-[1280px] items-center justify-between gap-4 px-6 py-4">
                <Link href="/" aria-label="DropRSVP home">
                    <Wordmark height={branding?.header_height ?? 44} />
                </Link>

                {/* Desktop nav */}
                <nav className="hidden items-center gap-6 md:flex">
                    {items.map((item, i) => <NavLink key={`${item.url}-${i}`} item={item} />)}
                </nav>

                <div className="flex items-center gap-2">
                    <AppearanceToggle />
                    <div className="hidden items-center gap-2 md:flex">
                        <Link href="/help" className="mr-1 text-sm font-medium text-foreground/70 transition-colors hover:text-foreground">Help</Link>
                        {auth?.user ? (
                            <Button asChild><Link href={dashboard()}>Dashboard</Link></Button>
                        ) : (
                            <>
                                <Button asChild variant="ghost"><Link href={login()}>Log in</Link></Button>
                                <Button asChild><Link href="/get-started">Sign up</Link></Button>
                            </>
                        )}
                    </div>
                    <button
                        type="button"
                        aria-label="Toggle menu"
                        onClick={() => setOpen((v) => !v)}
                        className="flex size-10 items-center justify-center rounded-lg border border-border md:hidden"
                    >
                        {open ? <X className="size-5" /> : <Menu className="size-5" />}
                    </button>
                </div>
            </div>

            {/* Mobile drawer */}
            {open && (
                <div className="border-t border-border bg-background md:hidden">
                    <nav className="mx-auto flex max-w-[1280px] flex-col gap-1 px-6 py-3">
                        {items.map((item, i) => (
                            <div key={`m-${item.url}-${i}`} className="py-1.5">
                                <NavLink item={item} onClick={() => setOpen(false)} />
                            </div>
                        ))}
                        <div className="mt-2 flex gap-2 border-t border-border pt-3">
                            {auth?.user ? (
                                <Button asChild className="flex-1"><Link href={dashboard()}>Dashboard</Link></Button>
                            ) : (
                                <>
                                    <Button asChild variant="outline" className="flex-1"><Link href={login()}>Log in</Link></Button>
                                    <Button asChild className="flex-1"><Link href="/get-started">Sign up</Link></Button>
                                </>
                            )}
                        </div>
                    </nav>
                </div>
            )}
        </header>
    );
}

export function PublicFooter() {
    const { footer } = usePage().props;
    // Footer is a single Puck block; render its props with the shared Footer component.
    const content = (footer as { content?: { type: string; props: Partial<FooterData> }[] } | undefined)?.content ?? [];
    const props = content.find((b) => b.type === 'Footer')?.props ?? {};

    return <Footer {...props} />;
}
