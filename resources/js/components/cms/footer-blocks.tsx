import { Link } from '@inertiajs/react';

/**
 * Plain (no-Puck) footer building blocks + renderer. Used by the public footer
 * on every page — so we render the Puck-authored footer WITHOUT shipping the
 * Puck runtime to visitors. The admin editor (footer-puck-config) reuses these
 * same components for its canvas.
 */

export interface FooterLink { label: string; url: string }
export interface FooterBlock { type: string; props: { tagline?: string; ctaLabel?: string; ctaUrl?: string; title?: string; links?: FooterLink[] } }

export function FooterBrand({ tagline, ctaLabel = 'Create an event', ctaUrl = '/get-started' }: { tagline?: string; ctaLabel?: string; ctaUrl?: string }) {
    return (
        <div className="max-w-xs">
            <Link href="/" className="text-xl font-bold tracking-tight">Drop<span className="text-muted-foreground">RSVP</span></Link>
            {tagline && <p className="mt-3 text-sm leading-relaxed text-muted-foreground">{tagline}</p>}
            {ctaLabel && <Link href={ctaUrl} className="mt-5 inline-flex rounded-full bg-foreground px-4 py-2 text-sm font-semibold text-background">{ctaLabel}</Link>}
        </div>
    );
}

export function FooterColumn({ title, links }: { title?: string; links?: FooterLink[] }) {
    return (
        <div>
            {title && <h3 className="text-xs font-semibold uppercase tracking-wider text-foreground">{title}</h3>}
            <nav className="mt-4 flex flex-col gap-2.5">
                {(links ?? []).filter((l) => l.label && l.url).map((l, i) => (
                    <Link key={i} href={l.url} className="text-sm text-muted-foreground transition-colors hover:text-foreground">{l.label}</Link>
                ))}
            </nav>
        </div>
    );
}

/** Render a Puck-shaped footer content array with plain React (no Puck runtime). */
export function FooterContent({ content }: { content: FooterBlock[] }) {
    return (
        <>
            {(content ?? []).map((b, i) => {
                if (b.type === 'Brand') {
return <FooterBrand key={i} {...b.props} />;
}

                if (b.type === 'Column') {
return <FooterColumn key={i} {...b.props} />;
}

                return null;
            })}
        </>
    );
}
