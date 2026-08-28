import { Link, usePage } from '@inertiajs/react';
import { CalendarDays, Menu, X } from 'lucide-react';
import { useState } from 'react';
import { FooterContent  } from '@/components/cms/footer-blocks';
import type {FooterBlock} from '@/components/cms/footer-blocks';
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
    const { auth, nav } = usePage().props;
    const items = (nav ?? []) as PublicNavItem[];
    const [open, setOpen] = useState(false);

    return (
        <header className="sticky top-0 z-30 border-b border-border bg-background/85 backdrop-blur">
            <div className="mx-auto flex max-w-[1280px] items-center justify-between gap-4 px-6 py-4">
                <Link href="/" className="text-2xl font-bold tracking-tight">
                    Drop<span className="text-primary">RSVP</span>
                </Link>

                {/* Desktop nav */}
                <nav className="hidden items-center gap-6 md:flex">
                    {items.map((item, i) => <NavLink key={`${item.url}-${i}`} item={item} />)}
                </nav>

                <div className="flex items-center gap-2">
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
    const content = (footer as { content?: FooterBlock[] } | undefined)?.content ?? [];

    return (
        <footer className="mt-auto border-t border-border bg-muted/30">
            {/* Puck-authored footer, rendered with the plain (no-Puck) renderer. */}
            <div className="mx-auto grid max-w-[1280px] gap-10 px-6 py-14 sm:grid-cols-2 lg:grid-cols-4">
                <FooterContent content={content} />
            </div>

            <div className="border-t border-border">
                <div className="mx-auto flex max-w-[1280px] flex-col items-center justify-between gap-3 px-6 py-6 text-sm text-muted-foreground sm:flex-row">
                    <span className="flex items-center gap-2 font-medium text-foreground">
                        <CalendarDays className="size-4" /> DropRSVP
                    </span>
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                        <Link href="/privacy-policy" className="hover:text-foreground">Privacy Policy</Link>
                        <Link href="/terms" className="hover:text-foreground">Terms &amp; Conditions</Link>
                        <span>© {new Date().getFullYear()} DropRSVP. All rights reserved.</span>
                    </div>
                </div>
            </div>
        </footer>
    );
}
