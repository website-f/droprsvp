import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';
import { CalendarDays, Menu, X } from 'lucide-react';
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

function FooterLink({ href, children }: { href: string; children: React.ReactNode }) {
    const cls = 'text-sm text-muted-foreground transition-colors hover:text-foreground';
    return href.startsWith('/')
        ? <Link href={href} className={cls}>{children}</Link>
        : <a href={href} className={cls}>{children}</a>;
}

export function PublicFooter() {
    const { auth, nav } = usePage().props;
    const items = (nav ?? []) as PublicNavItem[];
    const year = new Date().getFullYear();

    return (
        <footer className="mt-auto border-t border-border bg-muted/30">
            <div className="mx-auto grid max-w-[1280px] gap-10 px-6 py-14 sm:grid-cols-2 lg:grid-cols-[1.6fr_1fr_1fr_1fr]">
                {/* Brand */}
                <div className="max-w-xs">
                    <Link href="/" className="text-xl font-bold tracking-tight">Drop<span className="text-muted-foreground">RSVP</span></Link>
                    <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Find your people, fill your events. Discovery, ticketing, seating and QR check-in — all in one place.
                    </p>
                    <Button asChild size="sm" className="mt-5">
                        <Link href={auth?.user ? '/dashboard' : '/get-started'}>Create an event</Link>
                    </Button>
                </div>

                {/* Discover */}
                <div>
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-foreground">Discover</h3>
                    <nav className="mt-4 flex flex-col gap-2.5">
                        <FooterLink href="/events">Browse events</FooterLink>
                        <FooterLink href="/blog">Blog</FooterLink>
                        <FooterLink href="/events">Categories</FooterLink>
                    </nav>
                </div>

                {/* For hosts */}
                <div>
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-foreground">For hosts</h3>
                    <nav className="mt-4 flex flex-col gap-2.5">
                        <FooterLink href={auth?.user ? '/dashboard' : '/get-started'}>Create an event</FooterLink>
                        <FooterLink href="/my/tickets">My tickets</FooterLink>
                        {!auth?.user && <FooterLink href="/login">Log in</FooterLink>}
                    </nav>
                </div>

                {/* Menu (from the CMS nav) */}
                <div>
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-foreground">{items.length > 0 ? 'Explore' : 'Company'}</h3>
                    <nav className="mt-4 flex flex-col gap-2.5">
                        {items.length > 0
                            ? items.map((item, i) => <FooterLink key={`f-${item.url}-${i}`} href={item.url}>{item.label}</FooterLink>)
                            : <FooterLink href="/">Home</FooterLink>}
                    </nav>
                </div>
            </div>

            <div className="border-t border-border">
                <div className="mx-auto flex max-w-[1280px] flex-col items-center justify-between gap-3 px-6 py-6 text-sm text-muted-foreground sm:flex-row">
                    <span className="flex items-center gap-2 font-medium text-foreground">
                        <CalendarDays className="size-4" /> DropRSVP
                    </span>
                    <span>&copy; {year} DropRSVP. All rights reserved.</span>
                </div>
            </div>
        </footer>
    );
}
