import { Link, usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { LogoMark, Wordmark } from '@/components/brand';

/**
 * The complete site footer as a single component. Rendered identically by the
 * public site AND the admin Puck editor (footer-puck-config), so the builder
 * canvas mirrors the live footer exactly. No Puck runtime is imported here, so
 * the public footer stays lightweight.
 */

export interface FooterLink { label: string; url: string }
export interface FooterColumnData { title: string; links: FooterLink[] }
export interface FooterData { tagline: string; ctaLabel: string; ctaUrl: string; columns: FooterColumnData[] }

export function Footer({ tagline, ctaLabel = 'Create an event', ctaUrl = '/get-started', columns = [] }: Partial<FooterData>) {
    const footerHeight = usePage().props.branding?.footer_height ?? 36;

    return (
        <footer className="mt-auto border-t border-border bg-muted/30">
            <div className="mx-auto grid max-w-[1280px] gap-10 px-6 py-14 sm:grid-cols-2 lg:grid-cols-4">
                {/* Brand */}
                <div className="max-w-xs">
                    <Link href="/" aria-label="DropRSVP home"><Wordmark height={footerHeight} /></Link>
                    {tagline && <p className="mt-3 text-sm leading-relaxed text-muted-foreground">{tagline}</p>}
                    <a href="mailto:support@droprsvp.com" className="mt-3 inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"><Mail className="size-3.5" /> support@droprsvp.com</a>
                    {ctaLabel && <Link href={ctaUrl} className="mt-5 inline-flex rounded-full bg-foreground px-4 py-2 text-sm font-semibold text-background">{ctaLabel}</Link>}
                </div>

                {/* Link columns */}
                {(columns ?? []).map((col, i) => (
                    <div key={i}>
                        {col.title && <h3 className="text-xs font-semibold uppercase tracking-wider text-foreground">{col.title}</h3>}
                        <nav className="mt-4 flex flex-col gap-2.5">
                            {(col.links ?? []).filter((l) => l.label && l.url).map((l, j) => (
                                <Link key={j} href={l.url} className="text-sm text-muted-foreground transition-colors hover:text-foreground">{l.label}</Link>
                            ))}
                        </nav>
                    </div>
                ))}
            </div>

            <div className="border-t border-border">
                <div className="mx-auto flex max-w-[1280px] flex-col items-center justify-between gap-3 px-6 py-6 text-sm text-muted-foreground sm:flex-row">
                    <LogoMark className="size-6" />
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                        <Link href="/contact" className="hover:text-foreground">Contact</Link>
                        <Link href="/privacy-policy" className="hover:text-foreground">Privacy Policy</Link>
                        <Link href="/terms" className="hover:text-foreground">Terms &amp; Conditions</Link>
                        <span>© {new Date().getFullYear()} DropRSVP. All rights reserved.</span>
                    </div>
                </div>
            </div>
        </footer>
    );
}
