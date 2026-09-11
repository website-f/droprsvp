import { Link, usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { Wordmark } from '@/components/brand';
import { platformLabel, SocialIcon } from '@/components/social-icons';

/**
 * The complete site footer as a single component. Rendered identically by the
 * public site AND the admin Puck editor (footer-puck-config), so the builder
 * canvas mirrors the live footer exactly. No Puck runtime is imported here, so
 * the public footer stays lightweight.
 */

export interface FooterLink { label: string; url: string }
export interface FooterColumnData { title: string; links: FooterLink[] }
export interface SocialLink { platform: string; url: string }
export interface FooterData {
    tagline: string; ctaLabel: string; ctaUrl: string; columns: FooterColumnData[];
    // Bottom legal row + branding — all editable from Admin → Footer.
    legalLinks: FooterLink[]; copyright: string; supportEmail: string;
    socials: SocialLink[];
    background: 'muted' | 'card' | 'plain';
}

export const DEFAULT_LEGAL_LINKS: FooterLink[] = [
    { label: 'Contact', url: '/en-my/contact/' },
    { label: 'Privacy Policy', url: '/en-my/privacy-policy/' },
    { label: 'Terms & Conditions', url: '/en-my/terms/' },
];
export const DEFAULT_COPYRIGHT = '© {year} DropRSVP. All rights reserved.';
export const DEFAULT_SUPPORT_EMAIL = 'support@droprsvp.com';

const BG: Record<string, string> = { muted: 'bg-muted/30', card: 'bg-card', plain: 'bg-background' };

export function Footer({
    tagline, ctaLabel = 'Create an event', ctaUrl = '/get-started', columns = [],
    legalLinks, copyright, supportEmail, socials, background = 'muted',
}: Partial<FooterData>) {
    const footerHeight = usePage().props.branding?.footer_height ?? 36;
    const legal = (legalLinks ?? DEFAULT_LEGAL_LINKS).filter((l) => l.label && l.url);
    const email = supportEmail ?? DEFAULT_SUPPORT_EMAIL;
    const rights = (copyright ?? DEFAULT_COPYRIGHT).replace('{year}', String(new Date().getFullYear()));
    const social = (socials ?? []).filter((s) => s.platform && s.url);

    return (
        <footer className={`mt-auto border-t border-border ${BG[background ?? 'muted'] ?? BG.muted}`}>
            <div className="mx-auto grid max-w-6xl gap-10 px-6 py-14 sm:grid-cols-2 lg:grid-cols-4">
                {/* Brand */}
                <div className="flex max-w-xs flex-col items-start">
                    <Link href="/en-my/" aria-label="DropRSVP home"><Wordmark height={footerHeight} /></Link>
                    {tagline && <p className="mt-3 text-sm leading-relaxed text-muted-foreground">{tagline}</p>}
                    {email && <a href={`mailto:${email}`} className="mt-3 flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"><Mail className="size-3.5 shrink-0" /> {email}</a>}
                    {ctaLabel && <Link href={ctaUrl} className="mt-5 inline-flex w-max rounded-full bg-foreground px-4 py-2 text-sm font-semibold text-background">{ctaLabel}</Link>}
                    {social.length > 0 && (
                        <div className="mt-5 flex flex-wrap items-center gap-2">
                            {social.map((s, i) => (
                                <a
                                    key={i}
                                    href={s.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label={platformLabel(s.platform)}
                                    title={platformLabel(s.platform)}
                                    className="flex size-9 items-center justify-center rounded-full border border-border text-muted-foreground transition-colors hover:border-foreground/30 hover:bg-foreground hover:text-background"
                                >
                                    <SocialIcon platform={s.platform} className="size-4" />
                                </a>
                            ))}
                        </div>
                    )}
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

            {(legal.length > 0 || rights) && (
                <div className="border-t border-border">
                    {/* Legal row — the brand already appears above, so no second mark here. */}
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-x-5 gap-y-2 px-6 py-6 text-sm text-muted-foreground sm:justify-end">
                        {legal.map((l, i) => (
                            <Link key={i} href={l.url} className="hover:text-foreground">{l.label}</Link>
                        ))}
                        {rights && <span>{rights}</span>}
                    </div>
                </div>
            )}
        </footer>
    );
}
